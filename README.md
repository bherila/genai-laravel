# genai-laravel

Provider-agnostic GenAI client for Laravel. Supports Google Gemini, AWS Bedrock (Claude), and Anthropic direct API through a single interface.

## Requirements

- PHP 8.4+
- Laravel 13

Laravel 13 ships a first-party AI SDK (`laravel/ai`) covering text generation
across the same providers. This package stays focused on what that abstraction
does not cover: runtime per-tenant credentials, raw provider parity (model
enumeration, normalised token/cost accounting), and automatic Office-document
conversion. If you only need plain text generation on Laravel 13, prefer
`laravel/ai`.

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
GEMINI_MODEL=gemini-3.6-flash

# Bedrock — uses Bearer-token auth, not AWS SigV4
BEDROCK_API_KEY=your-bedrock-bearer-token
BEDROCK_SESSION_TOKEN=   # optional, for temporary credentials
BEDROCK_REGION=us-east-1
BEDROCK_MODEL=us.anthropic.claude-haiku-4-5-20251001-v1:0

# Anthropic
ANTHROPIC_API_KEY=your-key
ANTHROPIC_MODEL=claude-sonnet-4-6
ANTHROPIC_MAX_TOKENS=8192
```

> **Pin your model IDs.** The defaults above track the models that were current
> when the release was cut — Google retires Gemini models on a
> [published schedule](https://ai.google.dev/gemini-api/docs/deprecations), and the
> right Bedrock prefix depends on your region and data-residency requirements
> (`anthropic.` in-region, `us.` / `eu.` / `apac.` / `global.` for cross-region
> inference profiles). Set `GEMINI_MODEL` / `BEDROCK_MODEL` / `ANTHROPIC_MODEL`
> explicitly in every environment you deploy.

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
// ['id' => 'toolu_01A…', 'name' => 'extract_invoice', 'input' => ['vendor' => 'Acme', ...]]
```

#### Completing the loop

Executing a tool and handing the result back needs three things the response
alone used to lack: the call's ID, the assistant turn replayed into the history
(Anthropic and Bedrock both reject a result whose call is not already there),
and a neutral way to express the result. All three are provider-agnostic:

```php
$messages = [['role' => 'user', 'content' => [ContentBlock::text($prompt)]]];

$ask = fn () => GenAiRequest::with($client)
    ->messages($messages)
    ->tools($toolConfig)
    ->generate();

$response = $ask();

while ($response->hasToolCalls()) {
    $messages[] = $response->assistantMessage();

    $results = [];
    foreach ($response->toolCalls as $call) {
        $results[] = ContentBlock::toolResultFor($call, $myTools->run($call['name'], $call['input']));
    }

    $messages[] = ['role' => 'user', 'content' => $results];
    $response = $ask();
}

echo $response->text;
```

`ContentBlock::toolResultFor()` carries both the call ID and the function name,
because Anthropic and Bedrock correlate results by ID while Gemini correlates by
name — one message, three wire formats:

| | Call | Result |
|---|---|---|
| Anthropic | `tool_use` | `tool_result` + `tool_use_id` |
| Bedrock | `toolUse` | `toolResult` + `toolUseId` + `status` |
| Gemini | `functionCall` | `functionResponse` matched by `name` |

A tool that failed is `ContentBlock::toolResultFor($call, $message, isError: true)`,
which becomes Anthropic's `is_error`, Bedrock's `status: "error"`, or a Gemini
`{"error": …}` response, so the model can recover instead of hanging.

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

### File APIs (large files)

Gemini and Anthropic both store uploaded files and let you reference them by ID
instead of re-sending the bytes on every turn; Bedrock does not. Branch on
`supportsFileApi()` rather than on the provider name.

Upload, reference, delete — the reference flows through the same builder as
inline bytes:

```php
$fileRef = $client->uploadFile($stream, 'application/pdf', 'report.pdf');

try {
    $response = GenAiRequest::with($client)
        ->withFileRef($fileRef, 'application/pdf')
        ->prompt('Summarise this report.')
        ->generate();

    echo $response->text;
} finally {
    $client->deleteFile($fileRef);
}
```

`ContentBlock::fileReference()` is the same thing at the message level, so an
uploaded file and inline bytes can sit side by side in one turn:

```php
->messages([[
    'role' => 'user',
    'content' => [
        ContentBlock::fileReference($fileRef, 'application/pdf'),
        ContentBlock::document($smallBase64, 'application/pdf'),
        ContentBlock::text('Which figures changed?'),
    ],
]])
```

The lower-level `converseWithFileRef($fileRef, $mime, $prompt)` is still there
for a single-file, single-prompt call.

`uploadFile()` returns the provider's reference as a string and throws on
failure — `GenAiUnsupportedOperationException` when the provider has no File API,
`GenAiUploadException` when the upload itself failed, `GenAiFileTooLargeException`
when the file is over the provider's ceiling. It never returns `null`.

> **Anthropic file scoping.** Files uploaded to the Anthropic Files API are
> scoped to the API **workspace**, not to a user or a conversation: any key in
> the same workspace can reference the returned `file_id`. Where tenants must
> not see each other's documents, give each one its own workspace and key, or
> keep sending bytes inline — which stores nothing. Anthropic also exposes
> listing and metadata, surfaced here as the provider-specific
> `AnthropicClient::listFiles()` and `::fileMetadata()`.

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
| `->toolCalls` | `[['id' => '...', 'name' => '...', 'input' => [...]], ...]` |
| `->usage` | Normalised `Usage` (tokens, cache tokens) — see below |
| `->raw` | Provider-specific raw response array |
| `->hasToolCalls()` | Whether the model called any tool |
| `->firstToolCall()` | First tool call, or `null` |
| `->toolCallByName('fn')` | Named tool call, or `null` |
| `->assistantMessage()` | This turn as a message to append before tool results |

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
        'us.anthropic.claude-haiku-4-5-20251001-v1:0' => ['input' => 0.8, 'output' => 4.0],
    ],
    'gemini' => [
        'gemini-3.6-flash' => ['input' => 0.1, 'output' => 0.4],
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

### Size limits

The limits differ by capability, not just by provider, so they are exposed as
three separate questions rather than one number:

```php
$client::maxInlineFileBytes('application/pdf'); // decoded bytes for one inline block
$client::maxUploadedFileBytes();                // decoded bytes via the File API, null when there is none
$client::maxFilesPerMessage();                  // document blocks per message, null when uncapped
$client::supportsFileApi();                     // whether uploadFile() will work at all
```

Every limit is expressed in **decoded** bytes. Clients enforce them before a
request leaves the process and throw `GenAiFileTooLargeException` — carrying
`$actualBytes` and `$limitBytes` — so an oversized file costs no round trip.

Office conversion is bounded too. `SpreadsheetToText` and `WordDocumentToPdf`
accept an optional `ConversionLimits` capping input size, output size, rows,
cells, and wall-clock time; XLSX and DOCX are ZIP containers, so a few kilobytes
on the wire can expand into gigabytes of worksheet. Use
`ConversionLimits::untrusted()` for end-user uploads:

```php
use Bherila\GenAiLaravel\FileConversion\ConversionLimits;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;

$text = SpreadsheetToText::convert($base64, $mime, ConversionLimits::untrusted());
```

Spreadsheet extraction truncates rather than throws when it hits a row, cell,
output, or time ceiling, and marks the cut with a `=== Truncated: … ===` line.

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
| File upload API | ✅ `uploadFile()` | ❌ inline only | ✅ `uploadFile()` |
| Inline file bytes | ✅ | ✅ | ✅ |
| Tool/function calling | ✅ | ✅ | ✅ |
| Tool-result round trip | ✅ (by name) | ✅ (by id) | ✅ (by id) |
| Max inline file (decoded) | 15 MB | 4.5 MB doc / 3.75 MB image | 24 MB doc / 5 MB image |
| Max uploaded file | 2 GB | n/a | 500 MB |
| Documents per message | unlimited | 5 | unlimited |
| System prompts | ✅ | ✅ | ✅ |
| `listModels()` | ✅ | ✅ (control-plane) | ✅ |
| `checkCredentials()` | ✅ | ✅ | ✅ |
| Pricing in catalog | ❌ | ❌ | ❌ |
| Image blocks (PNG/JPEG/GIF/WebP) | ✅ | ✅ | ✅ |
| Office-format documents | auto-convert 📄📊 | ✅ native | auto-convert 📄📊 |
| Auto DOC/DOCX → PDF (with phpword + dompdf) | ✅ | n/a | ✅ |
| Auto XLSX/XLS/ODS/CSV → text (with phpspreadsheet) | ✅ | n/a | ✅ |

## License

This package is released under the [MIT License](LICENSE).
