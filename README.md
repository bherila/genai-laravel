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

# Bedrock — uses Bearer-token auth, not AWS SigV4
BEDROCK_API_KEY=your-bedrock-bearer-token
BEDROCK_SESSION_TOKEN=   # optional, for temporary credentials
BEDROCK_REGION=us-east-1
BEDROCK_MODEL=us.anthropic.claude-haiku-4-20250514-v1:0

# Anthropic
ANTHROPIC_API_KEY=your-key
ANTHROPIC_MODEL=claude-sonnet-4-6
ANTHROPIC_MAX_TOKENS=8192
```

> **Bedrock auth:** this package authenticates against Bedrock with a bearer
> token (`Authorization: Bearer …`), not AWS SigV4. `BEDROCK_API_KEY` is the
> bearer token itself — there is no separate `BEDROCK_SECRET_KEY`. If you are
> coming from the AWS SDK and have IAM access-key-ID + secret-access-key
> credentials, those are not the right shape for this package; use a Bedrock
> bearer token instead.

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

## Retry behaviour

All providers retry transient failures transparently. `429` honors the
`Retry-After: <seconds>` response header; `502 / 503 / 504` use exponential
backoff. `400 / 401 / 403 / 404` are never retried. After the budget is spent,
`GenAiRateLimitException::$retryAfter` carries the last server-suggested delay
so you can re-queue work.

```env
GENAI_RETRY_MAX_ATTEMPTS=3        # total attempts including the first; 1 disables retries
GENAI_RETRY_BACKOFF_BASE_MS=1000  # exponential backoff base (no Retry-After header)
GENAI_RETRY_BACKOFF_MAX_MS=30000  # cap on any single sleep
```

Override per client by passing a `RetryStrategy` to the constructor — useful in
tests, where injecting a `sleeper` closure keeps the suite fast:

```php
use Bherila\GenAiLaravel\Http\RetryStrategy;

new AnthropicClient(
    apiKey: '...',
    retry: new RetryStrategy(maxAttempts: 1), // disable retries
);
```

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
— populate them yourself via `PricingBook` if you need cost tracking alongside
model selection.

### Pricing table (`PricingBook`)

Supply your own per-million-token prices for any of the three providers
(`anthropic`, `bedrock`, `gemini`) and the package will both decorate
`ModelInfo` and turn `Usage` records into dollar costs:

```php
use Bherila\GenAiLaravel\PricingBook;

$book = PricingBook::fromArray([
    'anthropic' => [
        'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3, 'cache_creation' => 3.75],
    ],
    'bedrock' => [
        'us.anthropic.claude-haiku-4-20250514-v1:0' => ['input' => 0.8, 'output' => 4.0],
    ],
    'gemini' => [
        'gemini-2.0-flash' => ['input' => 0.1, 'output' => 0.4],
    ],
]);

// Decorate listModels() output with prices
$models = $book->enrichAll($client->listModels());

// Compute cost for a specific call
$cost = $book->estimateCost($response->usage, $client->provider(), $client->model());
```

`PricingBook::fromConfig()` reads the same shape from the `genai.pricing` config
key, so application-wide pricing can live alongside provider config. Existing
non-null cost fields on a `ModelInfo` are preserved by `enrich()`, and
`estimateCost()` / `priceFor()` return `null` when no price is registered for
the requested `(provider, modelId)`.

## File type support

Each provider accepts a different set of file formats natively. The clients
validate MIME types up front and fail fast with an actionable error rather than
round-tripping a request the API is going to reject. Images (PNG / JPEG / GIF /
WebP) are routed to the correct `image` block shape automatically.

For Anthropic and Gemini — which only accept PDF and text-type documents — this
package can auto-convert Office formats by treating `phpoffice/phpword` (+ a PDF
renderer) and `phpoffice/phpspreadsheet` as optional peer dependencies:

- **Word docs (`.doc`, `.docx`, `.odt`, `.rtf`) → PDF** via PhpWord + Dompdf so
  layout, tables, and fonts survive. The rendered PDF is sent through
  Anthropic's native PDF pipeline or Gemini's PDF vision pipeline.
- **Spreadsheets (`.xlsx`, `.xls`, `.ods`, `.csv`) → tab-separated text** via
  PhpSpreadsheet. Cell data is emitted as a text block with a
  `=== Sheet: <name> ===` header per sheet.

Neither dependency is in `require` — when a peer is missing the client falls
back to a clear `GenAiFatalException` telling the caller what to install.

| MIME type              | Gemini            | Bedrock          | Anthropic         |
|------------------------|-------------------|------------------|-------------------|
| `application/pdf`      | ✅ (vision)       | ✅ `document`    | ✅ `document`     |
| `text/plain`           | ✅                | ✅               | ✅ `document`     |
| `text/markdown`        | ✅ (text only)    | ✅               | convert to text   |
| `text/html`            | ✅ (text only)    | ✅               | convert to text   |
| `text/csv`             | auto-convert 📊   | ✅               | auto-convert 📊   |
| `application/xml`      | ✅ (text only)    | —                | convert to text   |
| `application/msword` (`.doc`)            | auto-convert 📄 | ✅ | auto-convert 📄 |
| `.docx` (`…wordprocessingml.document`)   | auto-convert 📄 | ✅ | auto-convert 📄 |
| `.odt` (OpenDocument Text)               | auto-convert 📄 | — | auto-convert 📄 |
| `application/rtf`                        | auto-convert 📄 | — | auto-convert 📄 |
| `application/vnd.ms-excel` (`.xls`)      | auto-convert 📊 | ✅ | auto-convert 📊 |
| `.xlsx` (`…spreadsheetml.sheet`)         | auto-convert 📊 | ✅ | auto-convert 📊 |
| `.ods` (OpenDocument Spreadsheet)        | auto-convert 📊 | — | auto-convert 📊 |
| `image/png`, `image/jpeg`, `image/gif`, `image/webp` | ✅ `inline_data` | ✅ `image` block | ✅ `image` block |

- 📄 Word → PDF requires `phpoffice/phpword` **and** a PhpWord PDF renderer
  (`dompdf/dompdf` recommended — alternatives: `mpdf/mpdf`, `tecnickcom/tcpdf`).
  Install with `composer require phpoffice/phpword dompdf/dompdf`.
- 📊 Spreadsheet → text requires `phpoffice/phpspreadsheet`. Install with
  `composer require phpoffice/phpspreadsheet`.

Bedrock natively accepts the Office formats via its own `document` block (the
Converse API lists `pdf, csv, doc, docx, xls, xlsx, html, txt, md` as native
formats), so no conversion runs for Bedrock requests.

> **Note:** PowerPoint (`.ppt`, `.pptx`, `.odp`) auto-conversion is not
> included in this PR — the only available PHP library (`phpoffice/phppresentation`)
> pins an older `phpoffice/phpspreadsheet` version that currently has open
> security advisories. Until that's resolved upstream, convert PowerPoint files
> to PDF yourself (e.g. via `libreoffice --convert-to pdf`) before sending them.

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
| Image blocks (PNG/JPEG/GIF/WebP) | ✅ | ✅ | ✅ |
| Office-format documents | auto-convert 📄📊 | ✅ native | auto-convert 📄📊 |
| Auto DOC/DOCX → PDF (with phpword + dompdf) | ✅ | n/a | ✅ |
| Auto XLSX/XLS/ODS/CSV → text (with phpspreadsheet) | ✅ | n/a | ✅ |

## License

This package is released under the [MIT License](LICENSE).
