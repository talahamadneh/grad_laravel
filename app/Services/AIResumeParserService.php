<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use UnexpectedValueException;
use Throwable;

class AIResumeParserService
{
    public function __construct(
        private GroqService $groq,
        private GeminiService $gemini
    ) {
    }

    public function parse(string $text): array
    {
        $started = microtime(true);
        $characters = mb_strlen($text);
        $prompt = $this->prompt($text);

        try {
            $content = $this->groq->generateJson($prompt, 4000);
            $parsed = $this->parseProviderJson($content);
            $normalized = $this->normalize($parsed);

            $this->logSuccess('groq', $characters, $started);

            return $normalized;
        } catch (UnexpectedValueException $exception) {
            $this->logInvalid('groq', $characters, $started);

            throw $exception;
        } catch (Throwable $exception) {
            $this->logProviderFailure('groq', $characters, $started);
        }

        try {
            $content = $this->gemini->generateJson($prompt, 4000);
            $parsed = $this->parseProviderJson($content);
            $normalized = $this->normalize($parsed);

            $this->logSuccess('gemini', $characters, $started);

            return $normalized;
        } catch (UnexpectedValueException $exception) {
            $this->logInvalid('gemini', $characters, $started);

            throw $exception;
        } catch (Throwable $exception) {
            $this->logProviderFailure('gemini', $characters, $started);

            throw new RuntimeException('Resume parsing service unavailable');
        }
    }

    private function prompt(string $text): string
    {
        return <<<PROMPT
Parse ONLY information explicitly present in the resume text.

Rules:
- Never invent missing information.
- Return valid JSON only.
- Do not include markdown or explanations.
- If a scalar field is missing, use null.
- If an array field is missing, use [].
- Preserve the candidate's original facts.
- Extract structured items only when the information is present.

Required JSON shape:
{
  "full_name": null,
  "professional_title": null,
  "summary": null,
  "email": null,
  "phone": null,
  "location": null,
  "linkedin": null,
  "github": null,
  "portfolio": null,
  "education": [],
  "skills": [],
  "experience": [],
  "projects": [],
  "certificates": [],
  "languages": []
}

Education items should use:
{"degree":"","university":"","field_of_study":"","start_date":"","end_date":""}

Skill items may be strings or {"name":""}.

Experience items should use:
{"title":"","company":"","start_date":"","end_date":"","description":""}

Project items should use:
{"name":"","link":null,"description":""}

Certificate items should use:
{"name":"","issuer":"","year":""}

Language items should use:
{"language":"","level":""}

Resume text:
{$text}
PROMPT;
    }

    private function parseProviderJson(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```json\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode(trim($content), true);

        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Resume parser returned malformed JSON.');
        }

        return $decoded;
    }

    private function normalize(array $data): array
    {
        return [
            'full_name' => $this->nullableString($data['full_name'] ?? null),
            'professional_title' => $this->nullableString($data['professional_title'] ?? null),
            'summary' => $this->nullableString($data['summary'] ?? null),
            'email' => $this->nullableString($data['email'] ?? null),
            'phone' => $this->nullableString($data['phone'] ?? null),
            'location' => $this->nullableString($data['location'] ?? null),
            'linkedin' => $this->nullableString($data['linkedin'] ?? null),
            'github' => $this->nullableString($data['github'] ?? null),
            'portfolio' => $this->nullableString($data['portfolio'] ?? null),
            'education' => $this->arrayField($data['education'] ?? []),
            'skills' => $this->arrayField($data['skills'] ?? []),
            'experience' => $this->arrayField($data['experience'] ?? []),
            'projects' => $this->arrayField($data['projects'] ?? []),
            'certificates' => $this->arrayField($data['certificates'] ?? []),
            'languages' => $this->arrayField($data['languages'] ?? []),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function arrayField(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function logSuccess(string $provider, int $characters, float $started): void
    {
        Log::info('Resume upload parsing succeeded', $this->safeContext($provider, $characters, $started));
    }

    private function logInvalid(string $provider, int $characters, float $started): void
    {
        Log::warning('Resume upload parser returned malformed JSON', $this->safeContext($provider, $characters, $started));
    }

    private function logProviderFailure(string $provider, int $characters, float $started): void
    {
        Log::warning('Resume upload parsing provider failed', $this->safeContext($provider, $characters, $started));
    }

    private function safeContext(string $provider, int $characters, float $started): array
    {
        return [
            'provider' => $provider,
            'characters' => $characters,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
