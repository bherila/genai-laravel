# genai-laravel

Provider-agnostic GenAI client for Laravel. Supports Google Gemini, AWS Bedrock (Claude), and Anthropic direct API through a single interface.

## Installation

```bash
composer require bherila/genai-laravel
```

Publish the config:

```bash
php artisan vendor:publish --tag=genai-config
```

## Configuration

Set your provider in `.env`:

```env
# Default provider: gemini, bedrock, or anthropic
GENAI_PROVIDER=gemini

# Gemini
GEMINI_API_KEY=your-key
GEMINI_MODEL=gemini-2.0-flash

# Bedrock
BEDROCK_API_KEY=your-aws-access-key-id
BEDROCK_SESSION_TOKEN=   # optional, for temporary credentials
BEDROCK_REGION=us-east-1
BEDROCK_MODEL=us.anthropic.claude-haiku-4-20250514-v1:0

# Anthropic
ANTHROPIC_API_KEY=your-key
ANTHROPIC_MODEL=claude-sonnet-4-6
ANTHROPIC_MAX_TOKENS=8192
```

## Usage

### Fluent builder (recommended)

`GenAiRequest` provides a uniform call site regardless of provider. Pass any `GenAiClient` to `::with()` — the rest of the chain is identical.

```php
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\Clients\GenAiClientFactory;

$client = GenAiClientFactory::make('anthropic'); // or 'bedrock', 'gemini'

$response = GenAiRequest::with($client)
    ->system('You are a financial analyst.')
    ->withFile(base64_encode(file_get_contents($path)), 'application/pdf')
    ->prompt('Extract key figures.')
    ->generate();

echo $response->text;
// or
foreach ($response->toolCalls as $call) {
    // ['name' => 'extract_data', 'input' => [...]]
}
```

#### Using multiple providers in one application

```php
// Different tenants, different providers — call site is identical
$client = match ($user->ai_provider) {
    'anthropic' => new AnthropicClient(apiKey: $user->anthropic_key, model: 'claude-sonnet-4-6'),
    'bedrock'   => new BedrockClient(apiKey: $creds->key, modelId: $creds->model, region: 'us-east-1'),
    default     => new GeminiClient(apiKey: $user->gemini_key),
};

$response = GenAiRequest::with($client)
    ->system($systemPrompt)
    ->withFiles($files)   // [['base64' => '...', 'mimeType' => 'application/pdf'], ...]
    ->prompt($userPrompt)
    ->tools($toolConfig)
    ->generate();
```

### Tool calling

Define tools once with `Schema` + `ToolDefinition`. Each client converts to its native wire format internally.

```php
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;

$toolConfig = new ToolConfig(
    tools: [
        new ToolDefinition(
            name: 'extract_invoice',
            description: 'Extract invoice fields',
            inputSchema: Schema::object([
                'vendor'  => Schema::string('Vendor name'),
                'amount'  => Schema::number('Total amount due'),
                'due_date' => Schema::string('Due date in YYYY-MM-DD'),
            ], required: ['vendor', 'amount']),
        ),
    ],
    choice: ToolChoice::any(),
);

$response = GenAiRequest::with($client)
    ->withFile($base64, 'application/pdf')
    ->prompt('Extract the invoice data.')
    ->tools($toolConfig)
    ->generate();

$call = $response->toolCallByName('extract_invoice');
// ['name' => 'extract_invoice', 'input' => ['vendor' => 'Acme', 'amount' => 1500.00, ...]]
```

#### Schema helpers

```php
Schema::string('Optional description')
Schema::number()
Schema::integer()
Schema::boolean()
Schema::object(['field' => Schema::string()], required: ['field'])
Schema::arrayOf(Schema::string())
Schema::enum(['a', 'b', 'c'], 'Pick one')
Schema::fromArray(['type' => 'string', 'format' => 'date'])  // wrap raw JSON Schema
```

#### Tool choice

```php
ToolChoice::auto()          // model decides whether to call a tool
ToolChoice::any()           // model must call at least one tool
ToolChoice::none()          // model must not call any tool
ToolChoice::tool('my_fn')   // model must call this specific tool
```

### Gemini File API (large files)

```php
$fileRef = $client->uploadFile($stream, 'application/pdf', 'report.pdf');
try {
    $response = GenAiRequest::with($client)
        ->messages([[
            'role' => 'user',
            'content' => [ContentBlock::text('Summarise this report.')],
        ]])
        ->generate();
    // or use the lower-level method directly:
    $raw = $client->converseWithFileRef($fileRef, 'application/pdf', 'Summarise.');
} finally {
    $client->deleteFile($fileRef);
}
```

### Dependency injection (single provider)

When your app uses one provider, bind it in a service provider and inject `GenAiClient`:

```php
// AppServiceProvider
$this->app->singleton(GenAiClient::class, fn () => GenAiClientFactory::make());
```

```php
use Bherila\GenAiLaravel\Contracts\GenAiClient;

class MyService
{
    public function __construct(private readonly GenAiClient $ai) {}

    public function analyse(string $text): string
    {
        return GenAiRequest::with($this->ai)
            ->prompt($text)
            ->generate()
            ->text;
    }
}
```

### Facade

```php
use Bherila\GenAiLaravel\Facades\GenAi;

$response = GenAi::converse($system, $messages, $toolConfig);
```

## GenAiResponse

`generate()` always returns a `GenAiResponse`:

| Property / method | Description |
|---|---|
| `->text` | Concatenated text output |
| `->toolCalls` | `[['name' => '...', 'input' => [...]], ...]` |
| `->usage` | Normalised `Usage` (tokens, cache tokens) — see below |
| `->raw` | Provider-specific raw response array |
| `->hasToolCalls()` | Whether the model called any tool |
| `->firstToolCall()` | First tool call, or `null` |
| `->toolCallByName('fn')` | Named tool call, or `null` |

### Token usage and cost

Every response exposes a `Usage` object with provider-agnostic token counts. The
clients normalise the three different wire shapes (Anthropic `input_tokens` /
Bedrock `inputTokens` / Gemini `promptTokenCount`) into one API:

```php
$response = GenAiRequest::with($client)->prompt('...')->generate();

$response->usage->inputTokens;              // non-cached prompt tokens
$response->usage->outputTokens;             // completion tokens
$response->usage->totalTokens;
$response->usage->cacheReadInputTokens;     // served from prompt cache
$response->usage->cacheCreationInputTokens; // written to prompt cache
$response->usage->raw;                      // provider-specific payload

// Estimate cost in USD given per-million-token prices for the model you used.
$cost = $response->usage->estimatedCostUsd(
    inputPerMillion: 3.00,
    outputPerMillion: 15.00,
    cacheReadPerMillion: 0.30,
    cacheCreationPerMillion: 3.75,
);
```

The three input buckets are non-overlapping (the Gemini adapter subtracts
`cachedContentTokenCount` from `promptTokenCount` to match Anthropic/Bedrock
semantics), so summing them gives total input work billed.

## Listing models

Every client implements `listModels(): ModelInfo[]`, hitting each provider's
catalog endpoint and normalising the result:

```php
$client = GenAi::client('anthropic'); // or 'bedrock', 'gemini'

foreach ($client->listModels() as $model) {
    $model->id;                          // call-ready identifier
    $model->name;                        // human-readable display name
    $model->provider;                    // "anthropic" | "bedrock" | "gemini"
    $model->description;                 // free-form, when provided
    $model->inputTokenLimit;             // context window, when advertised
    $model->outputTokenLimit;            // max completion tokens, when advertised
    $model->inputCostPerMillionTokens;   // null — no provider returns pricing
    $model->outputCostPerMillionTokens;  // null — no provider returns pricing
    $model->raw;                         // provider-specific entry
}
```

Endpoints used: Anthropic `GET /v1/models`, Bedrock
`GET https://bedrock.{region}.amazonaws.com/foundation-models` (control-plane,
not `bedrock-runtime`), Gemini `GET /v1beta/models`. Gemini entries that don't
support `generateContent` (embeddings, etc.) are filtered out. None of the
provider catalog APIs currently return pricing, so the cost fields are nullable
— populate them yourself from your own pricing table if you need cost tracking
alongside model selection.

## Providers

| Feature | Gemini | Bedrock | Anthropic |
|---|---|---|---|
| File upload API | ✅ `uploadFile()` | ❌ inline only | ❌ inline only |
| Inline file bytes | ✅ | ✅ | ✅ |
| Tool/function calling | ✅ | ✅ | ✅ |
| File size limit | 20 MB | 4.5 MB | 4.5 MB |
| System prompts | ✅ | ✅ | ✅ |
| `listModels()` | ✅ | ✅ (control-plane) | ✅ |
| Pricing in catalog | ❌ | ❌ | ❌ |

## License

MIT
