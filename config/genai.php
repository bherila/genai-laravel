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
    | Office-document conversion limits
    |--------------------------------------------------------------------------
    | Applied when a client converts an Office document on your behalf — DOCX to
    | PDF, XLSX to text. These are best-effort guards against runaway documents
    | (a huge export, a sparse sheet with a cell at XFD1048576), NOT a security
    | boundary: only `max_input_bytes` is checked before the file reaches
    | PhpSpreadsheet or PhpWord, and neither library can be interrupted once it
    | starts. Converting documents from untrusted users safely needs a separate
    | process with an enforced memory cap and CPU limit. See the
    | ConversionLimits class docblock.
    */
    'conversion' => [
        'max_input_bytes' => (int) env('GENAI_CONVERSION_MAX_INPUT_BYTES', 33554432),
        'max_output_bytes' => (int) env('GENAI_CONVERSION_MAX_OUTPUT_BYTES', 33554432),
        'max_rows_per_sheet' => (int) env('GENAI_CONVERSION_MAX_ROWS_PER_SHEET', 100000),
        'max_cells' => (int) env('GENAI_CONVERSION_MAX_CELLS', 2000000),
        'max_seconds' => (float) env('GENAI_CONVERSION_MAX_SECONDS', 60),
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
        //     'us.anthropic.claude-haiku-4-5-20251001-v1:0' => ['input' => 0.8, 'output' => 4.0],
        // ],
        // 'gemini' => [
        //     'gemini-3.6-flash' => ['input' => 0.1, 'output' => 0.4],
        // ],
    ],

    'providers' => [

        /*
        |--------------------------------------------------------------------------
        | Google Gemini
        |--------------------------------------------------------------------------
        */
        'gemini' => [
            // Site-wide API key. For per-user or per-tenant keys, pass credentials
            // to the factory instead of relying on config:
            //   GenAiClientFactory::make(credentials: new GeminiCredentials(apiKey: $key))
            'api_key' => env('GEMINI_API_KEY'),

            // Model ID. Pin this explicitly in your own .env: Google retires Gemini
            // models on a published schedule, and this default is a placeholder
            // that keeps the package bootable rather than a recommendation — it
            // is not tracked for currency.
            // See https://ai.google.dev/gemini-api/docs/models/gemini and
            // https://ai.google.dev/gemini-api/docs/deprecations
            'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),

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

            // Bedrock model ID or inference profile ARN. The default is the US
            // cross-region inference profile for Claude Haiku 4.5; other regions
            // and data-residency requirements need a different prefix
            // (`anthropic.` for in-region, `eu.` / `apac.` / `global.` otherwise),
            // so pin BEDROCK_MODEL explicitly rather than relying on this default.
            // https://docs.aws.amazon.com/bedrock/latest/userguide/model-card-anthropic-claude-haiku-4-5.html
            'model' => env('BEDROCK_MODEL', 'us.anthropic.claude-haiku-4-5-20251001-v1:0'),

            // HTTP timeout in seconds for long-running inference calls.
            'timeout' => (int) env('BEDROCK_TIMEOUT', 240),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Instrumentation
    |--------------------------------------------------------------------------
    |
    | Sentry instrumentation is optional and activates only when the Sentry PHP
    | SDK is installed and tracing is enabled for the current transaction.
    | Prompt/response capture is disabled by default because those values often
    | contain user or customer data.
    |
    */
    'instrumentation' => [
        'sentry' => [
            'enabled' => env('GENAI_SENTRY_INSTRUMENTATION_ENABLED', true),
            'agent_name' => env('GENAI_AGENT_NAME', env('APP_NAME', 'laravel')),
            'conversation_id' => env('GENAI_CONVERSATION_ID'),
            'record_content' => env('GENAI_SENTRY_RECORD_CONTENT', false),
        ],
    ],

    /* Subscription-backed asynchronous execution. Routes are opt-in and auth fails closed. */
    'mcp' => [
        'enabled' => env('GENAI_MCP_ENABLED', false),
        'personal_tokens' => ['enabled' => env('GENAI_MCP_PERSONAL_TOKENS', false)],
        'lease' => ['seconds' => (int) env('GENAI_MCP_LEASE_SECONDS', 900), 'max_total_seconds' => (int) env('GENAI_MCP_MAX_LEASE_SECONDS', 3600)],
        'limits' => [
            'max_enqueue_json_bytes' => (int) env('GENAI_MCP_MAX_REQUEST_BYTES', 2097152),
            'max_attachments' => (int) env('GENAI_MCP_MAX_ATTACHMENTS', 20),
            'max_attachment_bytes' => (int) env('GENAI_MCP_MAX_ATTACHMENT_BYTES', 104857600),
            'max_completion_bytes' => (int) env('GENAI_MCP_MAX_COMPLETION_BYTES', 1048576),
            'max_completion_text_chars' => (int) env('GENAI_MCP_MAX_COMPLETION_TEXT_CHARS', 100000),
            'max_tool_calls' => (int) env('GENAI_MCP_MAX_TOOL_CALLS', 16),
            'max_json_nesting' => (int) env('GENAI_MCP_MAX_JSON_NESTING', 32),
        ],
        'attachments' => ['disk' => env('GENAI_MCP_ATTACHMENT_DISK', 'local')],
        'retention' => ['terminal_days' => (int) env('GENAI_MCP_RETENTION_DAYS', 30)],
        'rest' => ['enabled' => true, 'prefix' => env('GENAI_MCP_REST_PREFIX', 'genai/mcp/v1'), 'throttle' => env('GENAI_MCP_THROTTLE', '60,1')],
        'server' => [
            'enabled' => env('GENAI_MCP_SERVER_ENABLED', false),
            'path' => env('GENAI_MCP_SERVER_PATH', 'genai/mcp'),
            'allowed_origins' => array_values(array_filter(explode(',', (string) env('GENAI_MCP_ALLOWED_ORIGINS', '')))),
            'allowed_hosts' => array_values(array_filter(explode(',', (string) env('GENAI_MCP_ALLOWED_HOSTS', '')))),
            'max_body_bytes' => (int) env('GENAI_MCP_MAX_BODY_BYTES', 262144),
            'session_ttl_seconds' => (int) env('GENAI_MCP_SESSION_TTL', 1800),
        ],
    ],

];
