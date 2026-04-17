# genai-laravel

Provider-agnostic GenAI client for Laravel. Supports Google Gemini and AWS Bedrock (Claude) through a single interface.

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
# Choose: gemini or bedrock
GENAI_PROVIDER=gemini

# Gemini
GEMINI_API_KEY=your-key
GEMINI_MODEL=gemini-2.0-flash

# Bedrock
BEDROCK_API_KEY=your-aws-access-key-id
BEDROCK_SECRET_KEY=your-aws-secret-access-key
BEDROCK_SESSION_TOKEN=   # optional, for temporary credentials
BEDROCK_REGION=us-east-1
BEDROCK_MODEL=us.anthropic.claude-haiku-4-20250514-v1:0
```

## Usage

### Dependency injection

```php
use Bherila\GenAiLaravel\Contracts\GenAiClient;

class MyService
{
    public function __construct(private GenAiClient $ai) {}

    public function analyse(string $text): string
    {
        $response = $this->ai->converse(
            system: [['text' => 'You are a helpful assistant.']],
            messages: [['role' => 'user', 'content' => [['text' => $text]]]],
        );
        return $this->ai->extractText($response);
    }
}
```

### Facade

```php
use Bherila\GenAiLaravel\Facades\GenAi;

$response = GenAi::converse(...);
$text = GenAi::extractText($response);
```

### Sending a file (Gemini — File API upload)

```php
$fileRef = $ai->uploadFile($stream, 'application/pdf', 'payslip.pdf');
try {
    $response = $ai->converseWithFileRef($fileRef, 'application/pdf', 'Extract the net pay.');
    $data = $ai->extractText($response);
} finally {
    $ai->deleteFile($fileRef);
}
```

### Sending a file (Bedrock — inline bytes)

```php
$bytes = base64_encode(file_get_contents($path));
$response = $ai->converseWithInlineFile(
    fileBytes: $bytes,
    mimeType: 'application/pdf',
    prompt: 'Extract key financial figures.',
    system: [['text' => 'You are a financial analyst.']],
);
```

### Tool/function calling

```php
use Bherila\GenAiLaravel\ToolDefinitionBuilder as Tdb;

// Bedrock toolConfig
$toolConfig = [
    'tools' => [
        Tdb::bedrockToolSpec('extract_data', 'Extract fields', [
            'type' => 'object',
            'properties' => ['amount' => Tdb::jsonNumber(), 'date' => Tdb::jsonString()],
        ]),
    ],
    'toolChoice' => ['any' => (object) []],
];

$response = $ai->converseWithInlineFile($bytes, 'application/pdf', 'Extract.', [], $toolConfig);
$calls = $ai->extractToolCalls($response);
// [['name' => 'extract_data', 'input' => ['amount' => 1234.56, 'date' => '2024-01-01']]]
```

```php
// Gemini toolConfig
$toolConfig = [
    'tools' => [['function_declarations' => [
        Tdb::functionDefinition('extract_data', 'Extract fields', [
            'amount' => Tdb::number(),
            'date' => Tdb::string(),
        ]),
    ]]],
    'toolConfig' => ['functionCallingConfig' => ['mode' => 'ANY']],
];
```

### Per-provider clients

```php
use Bherila\GenAiLaravel\Clients\GenAiClientFactory;

$gemini  = GenAiClientFactory::make('gemini');
$bedrock = GenAiClientFactory::make('bedrock');
```

## Providers

| Feature | Gemini | Bedrock |
|---|---|---|
| File upload API | ✅ `uploadFile()` | ❌ inline only |
| Inline file bytes | ✅ | ✅ |
| Tool/function calling | ✅ | ✅ |
| File size limit | 20 MB (practical) | 4.5 MB |
| System prompts | ✅ `systemInstruction` | ✅ `system` blocks |

## License

MIT
