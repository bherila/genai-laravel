<?php

namespace Bherila\GenAiLaravel\Tests\Live;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\GenAiRequest;
use Orchestra\Testbench\TestCase;

/**
 * Opt-in smoke tests that talk to the real provider APIs.
 *
 * Mocked HTTP tests pin the wire format this package *sends*; they cannot tell
 * you that a default model ID has been retired or that a provider changed the
 * shape it accepts. These tests do, at the cost of needing credentials — so they
 * are excluded from the default test suite and skip themselves when a provider
 * is not configured.
 *
 *   GENAI_LIVE_TESTS=1 GEMINI_API_KEY=… vendor/bin/phpunit --testsuite Live
 *
 * Set GENAI_LIVE_PROVIDERS to a comma-separated subset (e.g. "gemini,anthropic")
 * to narrow the run further.
 */
class ProviderSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (self::env('GENAI_LIVE_TESTS') === '') {
            $this->markTestSkipped('Live tests are opt-in; set GENAI_LIVE_TESTS=1 to run them.');
        }
    }

    public function test_gemini_answers_a_trivial_prompt(): void
    {
        $this->assertAnswersTrivialPrompt($this->gemini());
    }

    public function test_gemini_lists_call_ready_model_ids(): void
    {
        $client = $this->gemini();

        $models = $client->listModels();
        $this->assertNotEmpty($models, 'Gemini returned no models.');

        foreach ($models as $model) {
            $this->assertStringStartsNotWith(
                'models/',
                $model->id,
                'listModels() must return call-ready IDs, not resource names.',
            );
        }
    }

    public function test_gemini_round_trips_an_uploaded_file(): void
    {
        $client = $this->gemini();

        $fileRef = $client->uploadFile("Widget revenue for Q3 was 42 dollars.\n", 'text/plain', 'genai-live-smoke.txt');
        $this->assertIsString($fileRef, 'Gemini File API upload returned no reference.');

        try {
            $text = $client->extractText(
                $client->converseWithFileRef($fileRef, 'text/plain', 'What was the Q3 widget revenue? Answer with the number only.'),
            );
            $this->assertStringContainsString('42', $text);
        } finally {
            $client->deleteFile($fileRef);
        }
    }

    public function test_anthropic_answers_a_trivial_prompt(): void
    {
        $this->assertAnswersTrivialPrompt($this->anthropic());
    }

    public function test_anthropic_round_trips_an_uploaded_file(): void
    {
        $client = $this->anthropic();

        $fileRef = $client->uploadFile("Widget revenue for Q3 was 42 dollars.\n", 'text/plain', 'genai-live-smoke.txt');
        $this->assertIsString($fileRef, 'Anthropic Files API upload returned no reference.');

        try {
            $text = $client->extractText(
                $client->converseWithFileRef($fileRef, 'text/plain', 'What was the Q3 widget revenue? Answer with the number only.'),
            );
            $this->assertStringContainsString('42', $text);
        } finally {
            $client->deleteFile($fileRef);
        }
    }

    public function test_bedrock_answers_a_trivial_prompt(): void
    {
        $this->assertAnswersTrivialPrompt($this->bedrock());
    }

    /**
     * The configured default model must still exist — this is the check that a
     * mocked suite structurally cannot make.
     */
    private function assertAnswersTrivialPrompt(GenAiClient $client): void
    {
        $response = GenAiRequest::with($client)
            ->system('Answer with a single digit and nothing else.')
            ->prompt('What is 2 + 2?')
            ->generate();

        $this->assertStringContainsString('4', $response->text, sprintf(
            '%s/%s returned no usable answer: %s',
            $client->provider(),
            $client->model(),
            json_encode($response->raw),
        ));
        $this->assertGreaterThan(0, $response->usage->outputTokens);
    }

    private function gemini(): GeminiClient
    {
        $key = $this->credentialsFor('gemini', 'GEMINI_API_KEY');

        return new GeminiClient(
            apiKey: $key,
            model: self::env('GEMINI_MODEL') ?: 'gemini-3.6-flash',
            // Text answers, not JSON — the default MIME forcing would wrap them.
            responseMimeType: null,
        );
    }

    private function anthropic(): AnthropicClient
    {
        $key = $this->credentialsFor('anthropic', 'ANTHROPIC_API_KEY');

        return new AnthropicClient(
            apiKey: $key,
            model: self::env('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-6',
        );
    }

    private function bedrock(): BedrockClient
    {
        $key = $this->credentialsFor('bedrock', 'BEDROCK_API_KEY');

        return new BedrockClient(
            apiKey: $key,
            modelId: self::env('BEDROCK_MODEL') ?: 'us.anthropic.claude-haiku-4-5-20251001-v1:0',
            region: self::env('BEDROCK_REGION') ?: 'us-east-1',
        );
    }

    /**
     * Skips the calling test unless this provider is both selected and credentialed.
     */
    private function credentialsFor(string $provider, string $keyVar): string
    {
        $selected = self::env('GENAI_LIVE_PROVIDERS');
        if ($selected !== '') {
            $names = array_map(trim(...), explode(',', $selected));
            if (! in_array($provider, $names, true)) {
                $this->markTestSkipped("Live tests for {$provider} were not selected via GENAI_LIVE_PROVIDERS.");
            }
        }

        $key = self::env($keyVar);
        if ($key === '') {
            $this->markTestSkipped("Live tests for {$provider} need {$keyVar}.");
        }

        return $key;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
