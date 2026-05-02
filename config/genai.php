<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default GenAI Provider
    |--------------------------------------------------------------------------
    | "gemini"    — Google Gemini (generateContent + File API)
    | "bedrock"   — AWS Bedrock Converse API (Claude models)
    | "anthropic" — Anthropic Messages API (direct, not via Bedrock)
    */
    'default' => env('GENAI_PROVIDER', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | Retry policy (applies to all providers)
    |--------------------------------------------------------------------------
    | Retries 429 (honoring `Retry-After`) and transient 5xx (502/503/504).
    | `max_attempts` includes the first request; set to 1 to disable retries.
    */
    'retry' => [
        'max_attempts' => (int) env('GENAI_RETRY_MAX_ATTEMPTS', 3),
        'backoff_base_ms' => (int) env('GENAI_RETRY_BACKOFF_BASE_MS', 1000),
        'backoff_max_ms' => (int) env('GENAI_RETRY_BACKOFF_MAX_MS', 30000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing table (USD per million tokens)
    |--------------------------------------------------------------------------
    | No provider catalog API returns pricing, so listModels() always leaves
    | cost fields null. Populate this table to let PricingBook::fromConfig()
    | enrich ModelInfo entries and compute costs from Usage.
    |
    | Shape: pricing.<provider>.<modelId> => [
    |     'input' => 3.0, 'output' => 15.0,
    |     'cache_read' => 0.3, 'cache_creation' => 3.75, // optional
    | ]
    */
    'pricing' => [
        // 'anthropic' => [
        //     'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0],
        // ],
        // 'bedrock' => [
        //     'us.anthropic.claude-haiku-4-20250514-v1:0' => ['input' => 0.8, 'output' => 4.0],
        // ],
        // 'gemini' => [
        //     'gemini-2.0-flash' => ['input' => 0.1, 'output' => 0.4],
        // ],
    ],

    'providers' => [

        /*
        |--------------------------------------------------------------------------
        | Google Gemini
        |--------------------------------------------------------------------------
        */
        'gemini' => [
            // API key. Per-user keys can be set dynamically via GenAiClientFactory::make()
            // with a custom key override; this is the site-wide fallback.
            'api_key' => env('GEMINI_API_KEY'),

            // Model ID. See https://ai.google.dev/gemini-api/docs/models/gemini
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),

            // HTTP timeout in seconds for long-running inference calls.
            'timeout' => (int) env('GEMINI_TIMEOUT', 240),

            // Generation response MIME type. Set to an empty string to let the
            // prompt choose a non-JSON text format such as TOON.
            'response_mime_type' => env('GEMINI_RESPONSE_MIME_TYPE', 'application/json'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Anthropic Messages API (direct)
        |--------------------------------------------------------------------------
        */
        'anthropic' => [
            // API key from https://console.anthropic.com/
            'api_key' => env('ANTHROPIC_API_KEY'),

            // Model ID. See https://docs.anthropic.com/en/docs/about-claude/models
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),

            // Maximum tokens in the response.
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 8192),

            // HTTP timeout in seconds for long-running inference calls.
            'timeout' => (int) env('ANTHROPIC_TIMEOUT', 240),
        ],

        /*
        |--------------------------------------------------------------------------
        | AWS Bedrock (Anthropic Claude)
        |--------------------------------------------------------------------------
        */
        'bedrock' => [
            // Bearer token sent as `Authorization: Bearer {api_key}`. This package
            // does not use AWS SigV4 — `api_key` is the bearer token itself, not
            // an AWS access key ID.
            'api_key' => env('BEDROCK_API_KEY'),

            // Optional STS session token, sent as X-Amz-Security-Token.
            'session_token' => env('BEDROCK_SESSION_TOKEN'),

            // AWS region.
            'region' => env('BEDROCK_REGION', 'us-east-1'),

            // Bedrock model ID or inference profile ARN.
            'model' => env('BEDROCK_MODEL', 'us.anthropic.claude-haiku-4-20250514-v1:0'),

            // HTTP timeout in seconds for long-running inference calls.
            'timeout' => (int) env('BEDROCK_TIMEOUT', 240),
        ],

    ],

];
