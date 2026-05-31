<?php

namespace Sentry {
    function trace(callable $callback, mixed $context): array
    {
        throw new \RuntimeException('Unsupported Sentry trace API should not be called.');
    }
}

namespace Sentry\Tracing {
    class SpanContext {}
}

namespace Bherila\GenAiLaravel\Tests\Unit {

    use Bherila\GenAiLaravel\ContentBlock;
    use Bherila\GenAiLaravel\Contracts\GenAiClient;
    use Bherila\GenAiLaravel\GenAiRequest;
    use Bherila\GenAiLaravel\ModelInfo;
    use Bherila\GenAiLaravel\ToolConfig;
    use Bherila\GenAiLaravel\Usage;
    use PHPUnit\Framework\TestCase;

    class GenAiRequestInstrumentationTest extends TestCase
    {
        public function test_generate_runs_with_unsupported_sentry_sdk_api(): void
        {
            $client = new class implements GenAiClient
            {
                public array $messages = [];

                public function provider(): string
                {
                    return 'test';
                }

                public function model(): string
                {
                    return 'test-model';
                }

                public static function maxFileBytes(): int
                {
                    return 1024;
                }

                public function converse(string $system, array $messages, ?ToolConfig $toolConfig = null): array
                {
                    $this->messages = $messages;

                    return [
                        'text' => 'ok',
                        'usage' => [
                            'input' => 10,
                            'output' => 4,
                        ],
                    ];
                }

                public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string
                {
                    return null;
                }

                public function deleteFile(string $fileRef): void {}

                public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?ToolConfig $toolConfig = null): array
                {
                    return [];
                }

                public function converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, string $system = '', ?ToolConfig $toolConfig = null): array
                {
                    return [];
                }

                public function extractText(array $response): string
                {
                    return (string) ($response['text'] ?? '');
                }

                public function extractToolCalls(array $response): array
                {
                    return [];
                }

                public function checkCredentials(): bool
                {
                    return true;
                }

                public function listModels(): array
                {
                    return [new ModelInfo('test-model', 'Test Model', 'test')];
                }

                public function extractUsage(array $response): Usage
                {
                    $usage = $response['usage'] ?? [];

                    return new Usage(
                        inputTokens: (int) ($usage['input'] ?? 0),
                        outputTokens: (int) ($usage['output'] ?? 0),
                        totalTokens: (int) ($usage['input'] ?? 0) + (int) ($usage['output'] ?? 0),
                    );
                }
            };

            $response = GenAiRequest::with($client)->prompt('hello')->generate();

            $this->assertSame('ok', $response->text);
            $this->assertSame(10, $response->usage->inputTokens);
            $this->assertSame('hello', $client->messages[0]['content'][0]->text);
            $this->assertInstanceOf(ContentBlock::class, $client->messages[0]['content'][0]);
        }
    }
}
