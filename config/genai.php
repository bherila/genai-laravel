<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default GenAI Provider
    |--------------------------------------------------------------------------
    | "gemini"  — Google Gemini (generateContent + File API)
    | "bedrock" — AWS Bedrock Converse API (Claude models)
    */
    'default' => env('GENAI_PROVIDER', 'gemini'),

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
        ],

        /*
        |--------------------------------------------------------------------------
        | AWS Bedrock (Anthropic Claude)
        |--------------------------------------------------------------------------
        */
        'bedrock' => [
            // AWS access key ID.
            'api_key' => env('BEDROCK_API_KEY'),

            // AWS secret access key (used as Bearer token in Bedrock's HTTP auth).
            'secret_key' => env('BEDROCK_SECRET_KEY'),

            // STS session token — required when using temporary IAM credentials.
            'session_token' => env('BEDROCK_SESSION_TOKEN'),

            // AWS region.
            'region' => env('BEDROCK_REGION', 'us-east-1'),

            // Bedrock model ID or inference profile ARN.
            'model' => env('BEDROCK_MODEL', 'us.anthropic.claude-haiku-4-20250514-v1:0'),
        ],

    ],

];
