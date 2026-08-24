# DevDocs-grounded interview question generation

The interview question endpoint remains:

```text
POST /api/ai/interview/questions
```

The feature no longer uses a local interview question bank. It does not store generated questions in the database and it does not use the removed Open Interview importer/model/table.

## Runtime flow

1. Laravel loads the selected job, its skills, and minimal student resume/project context.
2. `DevDocsRetrievalService` maps supported skills to DevDocs documentation slugs.
3. Laravel fetches DevDocs metadata from `https://devdocs.io/docs.json`.
4. Laravel fetches documentation index/content from `https://documents.devdocs.io/{slug}/index.json` and `https://documents.devdocs.io/{slug}/db.json`.
5. HTML documentation is converted into readable plain text.
6. `InterviewQuestionGenerationService` sends only the job context, minimal resume context, and retrieved DevDocs text to Groq.
7. The AI must return exactly 20 multiple-choice questions.
8. Laravel validates the response locally before returning it.

## Skill mapping

The current allowlist maps common project skills to DevDocs slugs:

```text
JavaScript, TypeScript, HTML, CSS, React, Node.js, PHP/Laravel, Python/FastAPI,
MySQL/SQL, PostgreSQL, MongoDB, Docker, Git, HTTP/REST/API
```

Unsupported skills do not trigger free generation. If no supported DevDocs documentation can be found, the API returns a clean 503 response.

## Grounding rules

The prompt instructs the external AI to:

- Use only the supplied DevDocs documentation for technical facts.
- Generate exactly 20 unique MCQ questions.
- Include options A, B, C, and D.
- Use a valid `correct_answer`.
- Cite source metadata for each question.
- Use resume/project context only for supplied facts and at most two questions.

## Caching

DevDocs metadata and documentation JSON are cached with:

```env
DEVDOCS_CACHE_TTL=86400
```

The cache stores documentation context only, not generated questions.

## Failure behavior

If DevDocs is unavailable, the endpoint returns:

```text
Trusted documentation source is temporarily unavailable. Please try again.
```

If the AI provider is disabled, unavailable, or returns invalid JSON, the endpoint returns:

```text
Interview question generation is temporarily unavailable. Please try again.
```

There is intentionally no fake local fallback for this feature.
