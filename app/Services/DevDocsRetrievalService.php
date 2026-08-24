<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class DevDocsRetrievalService
{
    private const DOCS_JSON_CACHE_KEY = 'devdocs:docs-json';

    public function retrieve(array $skills, string $jobTitle = '', string $jobDescription = '', string $jobLevel = ''): array
    {
        $startedAt = microtime(true);
        $timing = [
            'skill_detection_ms' => 0,
            'devdocs_index_loading_ms' => 0,
            'candidate_section_generation_ms' => 0,
            'ranking_ms' => 0,
            'deduplication_ms' => 0,
            'final_section_selection_ms' => 0,
            'total_devdocs_retrieval_ms' => 0,
            'candidate_entries_scanned' => 0,
            'ranking_candidates_considered' => 0,
        ];

        $stageStartedAt = microtime(true);
        $terms = $this->searchTerms($skills, $jobTitle, $jobDescription);
        $profile = $this->retrievalProfile($skills, $jobTitle, $jobDescription, $jobLevel);
        $priorityKeywords = $this->priorityKeywords($skills, $jobTitle, $jobDescription, $profile);
        $timing['skill_detection_ms'] = $this->elapsedMs($stageStartedAt);

        $stageStartedAt = microtime(true);
        $documents = $this->documents();
        $timing['devdocs_index_loading_ms'] += $this->elapsedMs($stageStartedAt);

        $selectedDocs = $this->selectDocs($terms, $documents);

        if ($selectedDocs->isEmpty()) {
            throw new RuntimeException('No supported DevDocs documentation was found for this job. Please try a role with common technical skills.');
        }

        $sections = [];
        foreach ($selectedDocs as $doc) {
            try {
                $sections = array_merge($sections, $this->sectionsForDoc($doc, $terms, $priorityKeywords, $profile, $timing));
            } catch (RuntimeException $exception) {
                Log::warning('DevDocs document retrieval failed for one document.', [
                    'slug' => $doc['slug'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (empty($sections)) {
            throw new RuntimeException('Trusted documentation source is temporarily unavailable. Please try again.');
        }

        $stageStartedAt = microtime(true);
        $sections = $this->budgetSections($sections);
        $timing['final_section_selection_ms'] += $this->elapsedMs($stageStartedAt);
        $timing['total_devdocs_retrieval_ms'] = $this->elapsedMs($startedAt);

        $covered = collect($sections)->pluck('skill')->filter()->unique()->values()->all();
        $relevantSkills = collect($terms)->pluck('skill')->unique()->values()->all();
        $contextChars = collect($sections)->sum(fn (array $section) => strlen($section['text']));

        Log::info('Interview timing: DevDocs retrieval.', array_merge($timing, [
            'selected_docs' => $selectedDocs->pluck('slug')->values()->all(),
            'sections_count' => count($sections),
        ]));

        return [
            'sections' => $sections,
            'relevant_technical_skills' => $relevantSkills,
            'explicit_skills' => collect($terms)->where('source', 'explicit')->pluck('skill')->unique()->values()->all(),
            'detected_from_requirements' => collect($terms)->where('source', 'requirements')->pluck('skill')->unique()->values()->all(),
            'retrieved_documents' => collect($sections)
                ->map(fn (array $section) => [
                    'skill' => $section['skill'],
                    'name' => $section['doc_name'],
                    'slug' => $section['doc_slug'],
                    'reference' => $section['source_reference'],
                    'topic' => $section['topic'] ?? null,
                    'ranking_reason' => $section['ranking_reason'] ?? null,
                ])
                ->unique('reference')
                ->values()
                ->all(),
            'covered_skills' => $covered,
            'uncovered_skills' => collect($relevantSkills)
                ->reject(fn (string $skill) => in_array($skill, $covered, true))
                ->values()
                ->all(),
            'context_character_count' => $contextChars,
            'estimated_context_tokens' => $this->estimateTokens((string) collect($sections)->pluck('text')->implode("\n")),
            'timing_ms' => $timing,
        ];
    }

    private function documents(): Collection
    {
        return Cache::remember(self::DOCS_JSON_CACHE_KEY, $this->cacheTtl(), function () {
            try {
                $response = Http::timeout($this->timeout())->get($this->baseUrl() . '/docs.json');
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Trusted documentation source is temporarily unavailable. Please try again.', 0, $exception);
            }

            if (!$response->successful()) {
                throw new RuntimeException('Trusted documentation source is temporarily unavailable. Please try again.');
            }

            return collect($response->json())
                ->filter(fn ($doc) => is_array($doc) && isset($doc['slug'], $doc['name']))
                ->map(fn (array $doc) => [
                    'name' => (string) $doc['name'],
                    'slug' => (string) $doc['slug'],
                    'type' => (string) ($doc['type'] ?? ''),
                ])
                ->values();
        });
    }

    private function selectDocs(array $terms, Collection $documents): Collection
    {
        $maxDocs = (int) config('services.devdocs.max_docs', 4);

        return collect($terms)
            ->sortBy(fn (array $term) => $term['source'] === 'explicit' ? 0 : 1)
            ->map(function (array $term) use ($documents) {
                $doc = $this->findDoc($term, $documents);

                return $doc ? array_merge($doc, [
                    'matched_skill' => $term['skill'],
                    'keywords' => $term['keywords'],
                    'term_source' => $term['source'],
                    'priority' => $term['priority'],
                ]) : null;
            })
            ->filter()
            ->unique('slug')
            ->take($maxDocs)
            ->values();
    }

    private function findDoc(array $term, Collection $documents): ?array
    {
        $candidates = $term['slugs'];

        foreach ($candidates as $candidate) {
            $doc = $documents->first(fn (array $doc) => $doc['slug'] === $candidate);
            if ($doc) {
                return $doc;
            }
        }

        foreach ($candidates as $candidate) {
            $doc = $documents->first(fn (array $doc) => Str::startsWith($doc['slug'], $candidate . '~'));
            if ($doc) {
                return $doc;
            }
        }

        return null;
    }

    private function sectionsForDoc(array $doc, array $terms, array $priorityKeywords, array $profile, array &$timing): array
    {
        $slug = $doc['slug'];

        $stageStartedAt = microtime(true);
        $index = $this->devDocsJson($slug, 'index.json');
        $database = $this->devDocsJson($slug, 'db.json');
        $timing['devdocs_index_loading_ms'] += $this->elapsedMs($stageStartedAt);

        $stageStartedAt = microtime(true);
        $paths = $this->rankedPaths($index['entries'] ?? [], $terms, $doc['keywords'] ?? [], $priorityKeywords, $doc['matched_skill'], $profile, $timing)
            ->take($this->sectionsPerDoc())
            ->values();
        $timing['ranking_ms'] += $this->elapsedMs($stageStartedAt);

        if ($paths->isEmpty()) {
            $paths = collect(array_keys($database))
                ->take($this->sectionsPerDoc())
                ->map(fn (string $path) => ['path' => $path, 'score' => 0])
                ->values();
        }

        $stageStartedAt = microtime(true);
        $sections = $paths
            ->map(function (array $rankedPath) use ($database, $doc, $priorityKeywords) {
                $path = $rankedPath['path'];
                $html = $database[$path] ?? null;
                if (!is_string($html) && str_contains($path, '#')) {
                    $html = $database[Str::before($path, '#')] ?? null;
                }
                if (!is_string($html) || trim($html) === '') {
                    return null;
                }

                $text = $this->htmlToText($html);
                if ($text === '') {
                    return null;
                }

                return [
                    'doc_name' => $doc['name'],
                    'doc_slug' => $doc['slug'],
                    'skill' => $doc['matched_skill'],
                    'term_source' => $doc['term_source'] ?? 'requirements',
                    'source' => 'devdocs',
                    'source_reference' => $this->baseUrl() . '/' . $doc['slug'] . '/' . $path,
                    'score' => $rankedPath['score'] + (int) ($doc['priority'] ?? 0),
                    'topic' => $rankedPath['topic'] ?? $this->topicKey($doc['matched_skill'], $path, '', ''),
                    'ranking_reason' => $rankedPath['ranking_reason'] ?? 'Matched job skills and DevDocs relevance.',
                    'text' => $this->focusedExcerpt($text, $priorityKeywords, $this->maxSectionChars()),
                ];
            })
            ->filter()
            ->values()
            ->all();
        $timing['candidate_section_generation_ms'] += $this->elapsedMs($stageStartedAt);

        return $sections;
    }

    private function devDocsJson(string $slug, string $file): array
    {
        $cacheKey = 'devdocs:' . $slug . ':' . $file;

        return Cache::remember($cacheKey, $this->cacheTtl(), function () use ($slug, $file) {
            try {
                $response = Http::timeout($this->timeout())
                    ->get($this->documentsUrl() . '/' . rawurlencode($slug) . '/' . $file);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Trusted documentation source is temporarily unavailable. Please try again.', 0, $exception);
            }

            if (!$response->successful() || !is_array($response->json())) {
                throw new RuntimeException('Trusted documentation source is temporarily unavailable. Please try again.');
            }

            return $response->json();
        });
    }

    private function rankedPaths(array $entries, array $terms, array $docKeywords, array $priorityKeywords, string $skill, array $profile, array &$timing): Collection
    {
        $keywords = collect($terms)
            ->flatMap(fn (array $term) => $term['keywords'])
            ->merge($docKeywords)
            ->merge($priorityKeywords)
            ->map(fn (string $keyword) => Str::lower($keyword))
            ->filter(fn (string $keyword) => strlen($keyword) >= 2)
            ->unique()
            ->take($this->maxRankingKeywords())
            ->values();

        $candidates = [];
        $scanned = 0;
        $scanLimit = $this->maxCandidateScan();
        $rankingLimit = $this->maxRankingCandidates();
        $trimAt = max($rankingLimit * 3, $rankingLimit + 1);

        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['path'])) {
                continue;
            }

            if ($scanned >= $scanLimit) {
                break;
            }
            $scanned++;

            $path = (string) ($entry['path'] ?? '');
            $name = (string) ($entry['name'] ?? '');
            $type = (string) ($entry['type'] ?? '');
            $haystack = Str::lower($name . ' ' . $type . ' ' . $path);
            $score = 0;

            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $score += 10;
                }
            }

            if (str_contains($haystack, 'guide') || str_contains($haystack, 'reference')) {
                $score += 2;
            }

            $topic = $this->topicKeyFromHaystack($skill, $path, $haystack);
            $pathScore = $this->interviewPathScoreFromHaystack($skill, $path, $haystack, $profile);
            $score += $pathScore['score'];

            if ($score <= 0 && count($candidates) >= $this->sectionsPerDoc()) {
                continue;
            }

            $candidates[] = [
                'path' => $path,
                'score' => $score,
                'topic' => $topic,
                'ranking_reason' => $pathScore['reason'],
            ];

            if (count($candidates) >= $trimAt) {
                usort($candidates, fn (array $left, array $right) => $right['score'] <=> $left['score']);
                $candidates = array_slice($candidates, 0, $rankingLimit);
            }
        }

        usort($candidates, fn (array $left, array $right) => $right['score'] <=> $left['score']);
        $candidates = array_slice($candidates, 0, $rankingLimit);

        $timing['candidate_entries_scanned'] += $scanned;
        $timing['ranking_candidates_considered'] += count($candidates);

        $stageStartedAt = microtime(true);
        $paths = collect($candidates)
            ->unique('path')
            ->pipe(fn (Collection $paths) => $this->diversePaths($paths))
            ->values();
        $timing['deduplication_ms'] += $this->elapsedMs($stageStartedAt);

        return $paths;
    }

    private function interviewPathScore(string $skill, string $path, string $name, string $type, array $profile): array
    {
        $haystack = Str::lower($path . ' ' . $name . ' ' . $type);

        return $this->interviewPathScoreFromHaystack($skill, $path, $haystack, $profile);
    }

    private function interviewPathScoreFromHaystack(string $skill, string $path, string $haystack, array $profile): array
    {
        $skill = Str::lower($skill);
        $score = 0;
        $reasons = [];

        foreach ($this->preferredTermsForSkill($skill, $profile) as $term) {
            if (str_contains($haystack, $term)) {
                $score += 18;
                $reasons[] = "matches job topic '{$term}'";
            }
        }

        if ($skill === 'laravel') {
            if (Str::startsWith($path, 'docs/')) {
                $score += 80;
                $reasons[] = 'Laravel guide instead of API internals';
            }

            foreach (['controllers', 'routing', 'requests', 'validation', 'middleware'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 70;
                    $reasons[] = "practical Laravel {$term}";
                }
            }

            foreach (['migrations', 'database', 'queries', 'eloquent', 'authentication'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 35;
                    $reasons[] = "common Laravel {$term}";
                }
            }

            if (Str::startsWith($path, 'api/')) {
                $score -= 90;
                $reasons[] = 'deprioritized Laravel internals';
            }
        }

        if ($skill === 'php') {
            foreach (['pdo.prepare', 'language.oop5.basic', 'language.types', 'language.variables', 'language.functions', 'language.oop5', 'language.exceptions', 'language.control-structures', 'function.array', 'ref.array', 'pdo', 'book.pdo'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 45;
                    $reasons[] = "practical PHP {$term}";
                }
            }

            foreach (['language.operators.logical', 'language.oop5.magic', 'eventhttprequest', 'eventhttp', 'gmagick', 'mysql_xdevapi', 'solr', 'swoole'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score -= 90;
                    $reasons[] = "deprioritized low-value PHP {$term}";
                }
            }
        }

        if ($skill === 'rest apis') {
            foreach (['rfc9110#section-9', 'rfc9110#section-15', 'rfc9110#section-6', 'rfc9110#section-8', 'guides/overview', 'methods', 'status codes', 'headers', 'content'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 55;
                    $reasons[] = "core HTTP {$term}";
                }
            }

            foreach (['content-security-policy', 'upgrade-insecure-requests', 'gateway', 'intermediary', 'cache', 'caching', 'cookies', 'session', 'webdav', 'proxy'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score -= $profile['level'] === 'senior' ? 20 : 75;
                    $reasons[] = "deprioritized HTTP {$term}";
                }
            }
        }

        if ($skill === 'git') {
            foreach (['git-add', 'git-status', 'git-commit', 'git-branch', 'git-merge', 'git-pull', 'git-push', 'git-clone', 'git-checkout', 'git-switch', 'git-diff', 'git-log'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 70;
                    $reasons[] = "practical Git workflow {$term}";
                }
            }

            foreach (['git-commit', 'git-branch'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 18;
                    $reasons[] = "foundational Git workflow {$term}";
                }
            }

            foreach (['configuration', 'git-config', 'plumbing', 'git-for-each-ref', 'git-http-backend', 'git-update-index', 'git-symbolic-ref', 'git-pack', 'git-hash-object'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score -= 100;
                    $reasons[] = "deprioritized Git internals {$term}";
                }
            }
        }

        if ($profile['level'] === 'junior') {
            foreach (['internal', 'advanced', 'performance', 'architecture', 'extensibility'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score -= 35;
                    $reasons[] = "deprioritized {$term} for junior level";
                }
            }
        } elseif ($profile['level'] === 'senior') {
            foreach (['performance', 'architecture', 'security', 'scaling', 'concurrency'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 60;
                    $reasons[] = "senior-level {$term}";
                }
            }
        }

        return [
            'score' => $score,
            'reason' => $reasons ? implode('; ', array_values(array_unique($reasons))) : 'Matched job skills and DevDocs relevance.',
        ];
    }

    private function preferredTermsForSkill(string $skill, array $profile): array
    {
        $skill = Str::lower($skill);
        $terms = collect($profile['preferred_terms'] ?? []);
        $general = ['basics', 'getting started', 'guide', 'common', 'practical', 'performance', 'architecture', 'security', 'scaling', 'concurrency'];

        $allowed = match ($skill) {
            'laravel' => ['request', 'response', 'validation', 'routing', 'controllers', 'middleware', 'database', 'query', 'eloquent', 'authentication'],
            'php' => ['functions', 'function', 'arrays', 'array', 'classes', 'class', 'oop', 'exceptions', 'types', 'pdo', 'database', 'query'],
            'rest apis' => ['request', 'response', 'methods', 'status', 'headers', 'content', 'auth', 'idempotency'],
            'git' => ['branch', 'commit', 'merge', 'pull', 'push', 'checkout', 'switch'],
            default => [],
        };

        return $terms
            ->filter(fn (string $term) => in_array($term, array_merge($general, $allowed), true))
            ->values()
            ->all();
    }

    private function diversePaths(Collection $paths): Collection
    {
        $firstByTopic = $paths->unique('topic');
        $remaining = $paths->reject(fn (array $path) => $firstByTopic->contains('path', $path['path']));

        return $firstByTopic->concat($remaining)->values();
    }

    private function budgetSections(array $sections): array
    {
        $sections = collect($sections)
            ->filter(fn (array $section) => trim((string) ($section['text'] ?? '')) !== '')
            ->map(function (array $section) {
                $section['fingerprint'] = $this->fingerprint($section['text']);

                return $section;
            })
            ->unique(fn (array $section) => ($section['skill'] ?? '') . '|' . $section['fingerprint'])
            ->groupBy('skill');

        $budget = $this->maxDocContextChars();
        $selected = collect();

        $firstPass = $sections->map(fn (Collection $group) => $group->sortByDesc('score')->first())->filter();
        foreach ($firstPass as $section) {
            $selected->push($section);
        }

        $remaining = $sections
            ->flatMap(fn (Collection $group) => $group->sortByDesc('score')->skip(1))
            ->sortByDesc('score');

        foreach ($remaining as $section) {
            $selected->push($section);
        }

        $used = 0;

        return $selected
            ->filter(function (array $section) use (&$used, $budget) {
                $length = strlen($section['text']);
                if ($used + $length > $budget) {
                    return false;
                }

                $used += $length;

                return true;
            })
            ->map(function (array $section) {
                unset($section['fingerprint'], $section['score']);

                return $section;
            })
            ->values()
            ->all();
    }

    private function focusedExcerpt(string $text, array $priorityKeywords, int $limit): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $ranked = collect($sentences)
            ->map(function (string $sentence, int $index) use ($priorityKeywords) {
                $lower = Str::lower($sentence);
                $score = collect($priorityKeywords)->reduce(
                    fn (int $carry, string $keyword) => $carry + (str_contains($lower, Str::lower($keyword)) ? 8 : 0),
                    0
                );

                return [
                    'index' => $index,
                    'sentence' => trim($sentence),
                    'score' => $score + ($index < 3 ? 2 : 0),
                ];
            })
            ->sortByDesc('score')
            ->values();

        $selected = [];
        $used = 0;

        foreach ($ranked as $item) {
            $sentence = $item['sentence'];
            $length = strlen($sentence);

            if ($length === 0 || $used + $length > $limit) {
                continue;
            }

            $selected[] = $item;
            $used += $length + 1;
        }

        if (empty($selected)) {
            return Str::limit($text, $limit, '');
        }

        return collect($selected)
            ->sortBy('index')
            ->pluck('sentence')
            ->implode(' ');
    }

    private function retrievalProfile(array $skills, string $jobTitle, string $jobDescription, string $jobLevel): array
    {
        $text = Str::lower($jobTitle . ' ' . $jobDescription . ' ' . implode(' ', $skills));
        $levelText = Str::lower($jobLevel . ' ' . $jobTitle . ' ' . $jobDescription);
        $level = match (true) {
            str_contains($levelText, 'senior') || str_contains($levelText, 'lead') || str_contains($levelText, 'principal') => 'senior',
            str_contains($levelText, 'mid') || str_contains($levelText, 'intermediate') => 'mid',
            default => 'junior',
        };

        $preferred = [];

        if (str_contains($text, 'backend') || str_contains($text, 'laravel') || str_contains($text, 'php') || str_contains($text, 'api')) {
            $preferred = array_merge($preferred, [
                'request', 'response', 'validation', 'routing', 'controllers', 'middleware',
                'database', 'query', 'eloquent', 'authentication', 'pdo', 'exceptions',
            ]);
        }

        if (str_contains($text, 'frontend') || str_contains($text, 'react') || str_contains($text, 'javascript') || str_contains($text, 'css')) {
            $preferred = array_merge($preferred, [
                'dom', 'events', 'components', 'hooks', 'state', 'props', 'forms', 'layout',
                'flexbox', 'grid', 'accessibility',
            ]);
        }

        if (str_contains($text, 'database') || str_contains($text, 'sql') || str_contains($text, 'mysql')) {
            $preferred = array_merge($preferred, [
                'query', 'indexes', 'transactions', 'constraints', 'joins', 'relationships', 'schema',
            ]);
        }

        if (str_contains($text, 'devops') || str_contains($text, 'docker') || str_contains($text, 'deployment')) {
            $preferred = array_merge($preferred, [
                'container', 'image', 'compose', 'network', 'volume', 'deployment', 'build',
            ]);
        }

        if (str_contains($text, 'git')) {
            $preferred = array_merge($preferred, ['branch', 'commit', 'merge', 'pull', 'push', 'checkout', 'switch']);
        }

        if ($level === 'senior') {
            $preferred = array_merge($preferred, ['performance', 'architecture', 'security', 'scaling', 'concurrency']);
        } else {
            $preferred = array_merge($preferred, ['basics', 'getting started', 'guide', 'common', 'practical']);
        }

        return [
            'level' => $level,
            'preferred_terms' => collect($preferred)->map(fn (string $term) => Str::lower($term))->unique()->values()->all(),
        ];
    }

    private function topicKey(string $skill, string $path, string $name, string $type): string
    {
        $haystack = Str::lower($path . ' ' . $name . ' ' . $type);

        return $this->topicKeyFromHaystack($skill, $path, $haystack);
    }

    private function topicKeyFromHaystack(string $skill, string $path, string $haystack): string
    {
        $topics = [
            'validation' => ['validation', 'validate'],
            'requests' => ['request', 'requests'],
            'routing' => ['routing', 'route'],
            'controllers' => ['controller'],
            'middleware' => ['middleware'],
            'database' => ['database', 'queries', 'query', 'pdo'],
            'eloquent' => ['eloquent', 'relationship'],
            'auth' => ['auth', 'authentication', 'authorization'],
            'functions' => ['function', 'functions'],
            'arrays' => ['array', 'arrays'],
            'oop' => ['oop', 'class', 'object', 'language.oop5'],
            'exceptions' => ['exception', 'error'],
            'types' => ['type', 'types'],
            'http-methods' => ['method', 'methods', 'section-9'],
            'http-status' => ['status', 'section-15'],
            'http-headers' => ['header', 'headers', 'section-6'],
            'git-branching' => ['branch', 'checkout', 'switch'],
            'git-commits' => ['commit', 'add', 'status'],
            'git-sync' => ['merge', 'pull', 'push', 'clone'],
            'layout' => ['layout', 'flexbox', 'grid'],
            'events' => ['event', 'events'],
            'components' => ['component', 'hooks', 'state', 'props'],
        ];

        foreach ($topics as $topic => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $topic;
                }
            }
        }

        return Str::lower($skill) . ':' . Str::before($path, '#');
    }

    private function priorityKeywords(array $skills, string $jobTitle, string $jobDescription, array $profile): array
    {
        return collect(array_merge(
            $skills,
            $profile['preferred_terms'] ?? [],
            preg_split('/[^a-zA-Z0-9+#.]+/', $jobTitle . ' ' . $jobDescription, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ))
            ->map(fn (string $word) => Str::lower(trim($word)))
            ->filter(fn (string $word) => strlen($word) >= 3)
            ->unique()
            ->values()
            ->all();
    }

    private function fingerprint(string $text): string
    {
        $normalized = Str::lower(preg_replace('/[^a-z0-9]+/i', ' ', $text) ?? $text);

        return md5(Str::limit(trim($normalized), 500, ''));
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    private function searchTerms(array $skills, string $jobTitle, string $jobDescription): array
    {
        $text = Str::lower($jobTitle . ' ' . $jobDescription . ' ' . implode(' ', $skills));
        $terms = [];

        foreach ($this->skillMap() as $label => $definition) {
            $aliases = $definition['aliases'];
            $matched = collect($skills)->first(function (string $skill) use ($aliases) {
                $normalized = Str::lower($skill);

                return collect($aliases)->contains(fn (string $alias) => $this->matchesAlias($normalized, $alias));
            });

            if ($matched) {
                $terms[] = [
                    'skill' => $definition['skill'],
                    'slugs' => $definition['slugs'],
                    'keywords' => array_values(array_unique(array_merge($aliases, [$label, $definition['skill']]))),
                    'source' => 'explicit',
                    'priority' => 100,
                ];
                continue;
            }

            $requirementsOnly = Str::lower($jobTitle . ' ' . $jobDescription);
            if (collect($aliases)->contains(fn (string $alias) => $this->matchesAlias($requirementsOnly, $alias))) {
                $terms[] = [
                    'skill' => $definition['skill'],
                    'slugs' => $definition['slugs'],
                    'keywords' => array_values(array_unique(array_merge($aliases, [$label, $definition['skill']]))),
                    'source' => 'requirements',
                    'priority' => 40,
                ];
            }
        }

        return collect($terms)
            ->unique('skill')
            ->values()
            ->all();
    }

    private function matchesAlias(string $text, string $alias): bool
    {
        $alias = Str::lower($alias);

        if (strlen($alias) <= 3) {
            return preg_match('/(?<![a-z0-9])' . preg_quote($alias, '/') . '(?![a-z0-9])/i', $text) === 1;
        }

        return str_contains($text, $alias);
    }

    private function skillMap(): array
    {
        return [
            'laravel' => ['skill' => 'Laravel', 'aliases' => ['laravel'], 'slugs' => ['laravel']],
            'javascript' => ['skill' => 'JavaScript', 'aliases' => ['javascript', 'js', 'ecmascript'], 'slugs' => ['javascript']],
            'typescript' => ['skill' => 'TypeScript', 'aliases' => ['typescript', 'ts'], 'slugs' => ['typescript']],
            'html' => ['skill' => 'HTML', 'aliases' => ['html', 'semantic'], 'slugs' => ['html']],
            'css' => ['skill' => 'CSS', 'aliases' => ['css', 'responsive', 'flexbox', 'grid'], 'slugs' => ['css']],
            'react' => ['skill' => 'React', 'aliases' => ['react', 'react.js', 'reactjs'], 'slugs' => ['react']],
            'node' => ['skill' => 'Node.js', 'aliases' => ['node', 'node.js', 'nodejs'], 'slugs' => ['node']],
            'php' => ['skill' => 'PHP', 'aliases' => ['php'], 'slugs' => ['php']],
            'python' => ['skill' => 'Python', 'aliases' => ['python', 'django', 'flask', 'fastapi'], 'slugs' => ['python']],
            'mysql' => ['skill' => 'MySQL', 'aliases' => ['mysql'], 'slugs' => ['mysql']],
            'postgresql' => ['skill' => 'PostgreSQL', 'aliases' => ['postgresql', 'postgres'], 'slugs' => ['postgresql']],
            'mongodb' => ['skill' => 'MongoDB', 'aliases' => ['mongodb', 'mongo'], 'slugs' => ['mongodb']],
            'docker' => ['skill' => 'Docker', 'aliases' => ['docker', 'container'], 'slugs' => ['docker']],
            'git' => ['skill' => 'Git', 'aliases' => ['git', 'version control'], 'slugs' => ['git']],
            'http' => ['skill' => 'REST APIs', 'aliases' => ['rest api', 'rest apis', 'restful', 'http api', 'http', 'api endpoint', 'request', 'response'], 'slugs' => ['http']],
        ];
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.devdocs.base_url', 'https://devdocs.io'), '/');
    }

    private function documentsUrl(): string
    {
        return rtrim((string) config('services.devdocs.documents_url', 'https://documents.devdocs.io'), '/');
    }

    private function timeout(): int
    {
        return min(8, max(2, (int) config('services.devdocs.timeout', 8)));
    }

    private function sectionsPerDoc(): int
    {
        return max(1, (int) config('services.devdocs.sections_per_doc', 2));
    }

    private function maxSectionChars(): int
    {
        return max(300, (int) config('services.devdocs.max_section_chars', 900));
    }

    private function maxDocContextChars(): int
    {
        return max(1200, (int) config('services.devdocs.max_doc_context_chars', 9000));
    }

    private function cacheTtl(): int
    {
        return (int) config('services.devdocs.cache_ttl', 86400);
    }

    private function maxRankingKeywords(): int
    {
        return max(10, (int) config('services.devdocs.max_ranking_keywords', 40));
    }

    private function maxCandidateScan(): int
    {
        return max(50, (int) config('services.devdocs.max_candidate_scan', 3000));
    }

    private function maxRankingCandidates(): int
    {
        return max(10, (int) config('services.devdocs.max_ranking_candidates', 250));
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
