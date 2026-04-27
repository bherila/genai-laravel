<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class CheckCredentialsTest extends TestCase
{
    // ── Anthropic ────────────────────────────────────────────────────────────

    public function test_anthropic_returns_true_on_200(): void
    {
        Http::fake(['https://api.anthropic.com/v1/models*' => Http::response(['data' => [], 'has_more' => false])]);

        $this->assertTrue((new AnthropicClient(apiKey: 'valid-key', model: 'claude-sonnet-4-6'))->checkCredentials());
    }

    public function test_anthropic_returns_false_on_401(): void
    {
        Http::fake(['https://api.anthropic.com/v1/models*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->assertFalse((new AnthropicClient(apiKey: 'bad-key', model: 'claude-sonnet-4-6'))->checkCredentials());
    }

    public function test_anthropic_returns_false_on_403(): void
    {
        Http::fake(['https://api.anthropic.com/v1/models*' => Http::response(['error' => 'forbidden'], 403)]);

        $this->assertFalse((new AnthropicClient(apiKey: 'bad-key', model: 'claude-sonnet-4-6'))->checkCredentials());
    }

    public function test_anthropic_throws_on_server_error(): void
    {
        Http::fake(['https://api.anthropic.com/v1/models*' => Http::response(['error' => 'oops'], 500)]);

        $this->expectException(GenAiFatalException::class);
        (new AnthropicClient(apiKey: 'key', model: 'claude-sonnet-4-6'))->checkCredentials();
    }

    public function test_anthropic_sends_api_key_header(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'has_more' => false])]);

        (new AnthropicClient(apiKey: 'my-key', model: 'claude-sonnet-4-6'))->checkCredentials();

        Http::assertSent(fn (Request $req) => $req->header('x-api-key')[0] === 'my-key'
            && str_contains($req->url(), '/v1/models'));
    }

    // ── Gemini ───────────────────────────────────────────────────────────────

    public function test_gemini_returns_true_on_200(): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models*' => Http::response(['models' => []])]);

        $this->assertTrue((new GeminiClient(apiKey: 'valid-key'))->checkCredentials());
    }

    public function test_gemini_returns_false_on_401(): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models*' => Http::response(['error' => ['message' => 'unauthorized']], 401)]);

        $this->assertFalse((new GeminiClient(apiKey: 'bad-key'))->checkCredentials());
    }

    public function test_gemini_returns_false_on_403(): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);

        $this->assertFalse((new GeminiClient(apiKey: 'bad-key'))->checkCredentials());
    }

    public function test_gemini_throws_on_server_error(): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models*' => Http::response(['error' => 'oops'], 500)]);

        $this->expectException(GenAiFatalException::class);
        (new GeminiClient(apiKey: 'key'))->checkCredentials();
    }

    public function test_gemini_sends_api_key_header(): void
    {
        Http::fake(['*' => Http::response(['models' => []])]);

        (new GeminiClient(apiKey: 'my-key'))->checkCredentials();

        Http::assertSent(fn (Request $req) => $req->header('x-goog-api-key')[0] === 'my-key'
            && str_contains($req->url(), '/v1beta/models'));
    }

    // ── Bedrock ──────────────────────────────────────────────────────────────

    public function test_bedrock_returns_true_on_200(): void
    {
        Http::fake(['https://bedrock.us-east-1.amazonaws.com/foundation-models' => Http::response(['modelSummaries' => []])]);

        $this->assertTrue((new BedrockClient(apiKey: 'valid-key', modelId: 'any'))->checkCredentials());
    }

    public function test_bedrock_returns_false_on_401(): void
    {
        Http::fake(['https://bedrock.us-east-1.amazonaws.com/foundation-models' => Http::response(['message' => 'unauthorized'], 401)]);

        $this->assertFalse((new BedrockClient(apiKey: 'bad-key', modelId: 'any'))->checkCredentials());
    }

    public function test_bedrock_returns_false_on_403(): void
    {
        Http::fake(['https://bedrock.us-east-1.amazonaws.com/foundation-models' => Http::response(['message' => 'forbidden'], 403)]);

        $this->assertFalse((new BedrockClient(apiKey: 'bad-key', modelId: 'any'))->checkCredentials());
    }

    public function test_bedrock_throws_on_server_error(): void
    {
        Http::fake(['https://bedrock.us-east-1.amazonaws.com/foundation-models' => Http::response(['error' => 'oops'], 500)]);

        $this->expectException(GenAiFatalException::class);
        (new BedrockClient(apiKey: 'key', modelId: 'any'))->checkCredentials();
    }

    public function test_bedrock_uses_configured_region(): void
    {
        Http::fake(['*' => Http::response(['modelSummaries' => []])]);

        (new BedrockClient(apiKey: 'key', modelId: 'any', region: 'eu-west-1'))->checkCredentials();

        Http::assertSent(fn (Request $req) => $req->url() === 'https://bedrock.eu-west-1.amazonaws.com/foundation-models');
    }
}
