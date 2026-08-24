<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'keys' => array_values(array_filter([
            env('GEMINI_API_KEY'),
            //env('GEMINI_API_KEY_1'),
           // env('GEMINI_API_KEY_2'),
           // env('GEMINI_API_KEY_3'),
           // env('GEMINI_API_KEY_4'),
        ])),
        'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
        'interview_max_output_tokens' => env('GEMINI_INTERVIEW_MAX_OUTPUT_TOKENS', 8192),
    ],

    'groq' => [
        'keys' => array_values(array_filter([
            env('GROQ_API_KEY'),
            // env('GROQ_API_KEY_1'),
            // env('GROQ_API_KEY_2'),
        ])),
        'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'max_completion_tokens' => env('GROQ_MAX_COMPLETION_TOKENS', 8192),
        'interview_max_completion_tokens' => env('GROQ_INTERVIEW_MAX_COMPLETION_TOKENS', 4500),
    ],

    'cv_analyzer' => [
        'url' => env('CV_ANALYZER_URL', 'http://127.0.0.1:8001'),
        'timeout' => env('CV_ANALYZER_TIMEOUT', 8),
    ],

    'cv_external_ai' => [
        'enabled' => env('CV_EXTERNAL_AI_ENABLED', false),
    ],

    'interview_external_ai' => [
        'enabled' => env('INTERVIEW_EXTERNAL_AI_ENABLED', true),
    ],

    'devdocs' => [
        'base_url' => env('DEVDOCS_BASE_URL', 'https://devdocs.io'),
        'documents_url' => env('DEVDOCS_DOCUMENTS_URL', 'https://documents.devdocs.io'),
        'timeout' => env('DEVDOCS_TIMEOUT', 12),
        'cache_ttl' => env('DEVDOCS_CACHE_TTL', 86400),
        'max_docs' => env('DEVDOCS_MAX_DOCS', 4),
        'sections_per_doc' => env('DEVDOCS_SECTIONS_PER_DOC', 2),
        'max_section_chars' => env('DEVDOCS_MAX_SECTION_CHARS', 900),
        'max_doc_context_chars' => env('DEVDOCS_MAX_DOC_CONTEXT_CHARS', 9000),
        'max_input_tokens' => env('INTERVIEW_AI_MAX_INPUT_TOKENS', 3000),
        'max_ranking_keywords' => env('DEVDOCS_MAX_RANKING_KEYWORDS', 40),
        'max_candidate_scan' => env('DEVDOCS_MAX_CANDIDATE_SCAN', 3000),
        'max_ranking_candidates' => env('DEVDOCS_MAX_RANKING_CANDIDATES', 250),
    ],

    'ai_provider' => env('AI_PROVIDER', 'groq'),

    'supabase' => [
    'url' => env('SUPABASE_URL'),
    'key' => env('SUPABASE_KEY'),
],

];
