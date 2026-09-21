<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiAuthenticationException;
use Bherila\GenAiLaravel\Exceptions\GenAiConfigurationException;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiModelUnavailableException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Bherila\GenAiLaravel\Http\RetryStrategy;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Every fixture below is a body shape taken from provider documentation (cited
 * in ProviderErrorClassifier) or observed on the wire. The negative controls
 * matter as much as the positives: a payload 400 that got reported as a
 * configuration failure would send an on-call engineer hunting for a setting
 * that is perfectly correct.
 */
class ProviderErrorClassificationTest extends TestCase
{
    private const URL = 'https://provider.example.test/v1/call';

    /**
     * @param  array<string, string>  $headers
     */
    private function failWith(int $status, string $body, array $headers = [], ?string $provider = null, ?string $modelId = null, int $maxAttempts = 1): Throwable
    {
        Http::fake([self::URL => Http::response($body, $status, $headers)]);

        $strategy = new RetryStrategy(maxAttempts: $maxAttempts, backoffBaseMs: 1, sleeper: function (int $ms): void {});
        if ($provider !== null) {
            $strategy = $strategy->forProvider($provider, $modelId);
        }

        try {
            $strategy->execute(fn () => Http::get(self::URL), 'Provider call');
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('Expected the strategy to throw.');
    }

    // ── model unavailable ────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('modelUnavailableFixtures')]
    public function test_rejected_model_id_becomes_model_unavailable(string $provider, string $modelId, int $status, string $body, array $headers): void
    {
        $e = $this->failWith($status, $body, $headers, $provider, $modelId);

        $this->assertInstanceOf(GenAiModelUnavailableException::class, $e);
        $this->assertSame($provider, $e->provider);
        $this->assertSame($modelId, $e->modelId);
        $this->assertStringContainsString($body, $e->getMessage(), 'The provider message must survive classification.');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string, 4: array<string, string>}>
     */
    public static function modelUnavailableFixtures(): array
    {
        return [
            // https://platform.claude.com/docs/en/api/errors — 404 not_found_error.
            // The "model: <id>" message is the observed wire text for an unknown model.
            'anthropic 404 not_found_error naming the model' => [
                'anthropic', 'claude-retired-1', 404,
                '{"type":"error","error":{"type":"not_found_error","message":"model: claude-retired-1"},"request_id":"req_011CSHoEeqs5C35K2UUqR7Fy"}',
                [],
            ],
            // https://repost.aws/knowledge-center/bedrock-validation-exception-errors
            'bedrock 400 ValidationException invalid model identifier' => [
                'bedrock', 'anthropic.claude-typo-v1:0', 400,
                '{"message":"The provided model identifier is invalid."}',
                ['x-amzn-ErrorType' => 'ValidationException:http://internal.amazon.com/coral/com.amazon.coral.validate/'],
            ],
            // Same cause, different shape name, and the type arrives in the body
            // rather than the header: https://repost.aws/knowledge-center/bedrock-invokemodel-api-error
            'bedrock 404 ResourceNotFoundException unresolvable foundation model' => [
                'bedrock', 'anthropic.claude-gone-v1:0', 404,
                '{"__type":"com.amazon.bedrock#ResourceNotFoundException","message":"Could not resolve the foundation model from the provided model identifier."}',
                [],
            ],
            // A base id that now requires an inference profile is a setting to
            // change, not a request to retry.
            'bedrock 400 on-demand throughput not supported for this id' => [
                'bedrock', 'anthropic.claude-3-5-sonnet-20241022-v2:0', 400,
                '{"message":"Invocation of model ID anthropic.claude-3-5-sonnet-20241022-v2:0 with on-demand throughput isn\'t supported. Retry your request with the ID or ARN of an inference profile that contains this model."}',
                ['x-amzn-ErrorType' => 'ValidationException'],
            ],
            // A 403 that names the model as inaccessible is the model's problem,
            // not the credential's: enabling model access fixes it.
            'bedrock 403 AccessDeniedException naming model access' => [
                'bedrock', 'anthropic.claude-locked-v1:0', 403,
                '{"message":"You don\'t have access to the model with the specified model ID."}',
                ['x-amzn-ErrorType' => 'AccessDeniedException'],
            ],
            // google.rpc.Status envelope (https://google.aip.dev/193) as returned
            // by the v1beta generateContent endpoint.
            'gemini 404 NOT_FOUND naming the model resource' => [
                'gemini', 'gemini-retired-pro', 404,
                '{"error":{"code":404,"message":"models/gemini-retired-pro is not found for API version v1beta, or is not supported for generateContent. Call ListModels to see the list of available models and their supported methods.","status":"NOT_FOUND"}}',
                [],
            ],
            // Documented string-code envelope: https://ai.google.dev/gemini-api/docs/api-errors
            'gemini 404 model_not_found code' => [
                'gemini', 'gemini-retired-pro', 404,
                '{"error":{"code":"model_not_found","message":"The specified model was not found."}}',
                [],
            ],
        ];
    }

    // ── authentication ───────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('authenticationFixtures')]
    public function test_rejected_credential_becomes_authentication_failure(string $provider, int $status, string $body, array $headers): void
    {
        $e = $this->failWith($status, $body, $headers, $provider, 'configured-model');

        $this->assertInstanceOf(GenAiAuthenticationException::class, $e);
        $this->assertSame($provider, $e->provider);
        $this->assertSame($status, $e->status);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: array<string, string>}>
     */
    public static function authenticationFixtures(): array
    {
        return [
            // https://platform.claude.com/docs/en/api/errors
            'anthropic 401 authentication_error' => [
                'anthropic', 401,
                '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}',
                [],
            ],
            'anthropic 403 permission_error naming no model' => [
                'anthropic', 403,
                '{"type":"error","error":{"type":"permission_error","message":"Your API key does not have permission to use the specified resource."}}',
                [],
            ],
            // https://docs.aws.amazon.com/bedrock/latest/APIReference/CommonErrors.html
            'bedrock 403 UnrecognizedClientException' => [
                'bedrock', 403,
                '{"message":"The security token included in the request is invalid."}',
                ['x-amzn-ErrorType' => 'UnrecognizedClientException'],
            ],
            // AccessDeniedException that talks about IAM, not about the model.
            'bedrock 403 AccessDeniedException on the IAM policy' => [
                'bedrock', 403,
                '{"message":"User: arn:aws:iam::123456789012:user/example is not authorized to perform: bedrock:InvokeModel because no identity-based policy allows the bedrock:InvokeModel action"}',
                ['x-amzn-ErrorType' => 'AccessDeniedException'],
            ],
            // A 400 that is really an authorization failure:
            // https://repost.aws/knowledge-center/bedrock-validation-exception-errors
            'bedrock 400 ValidationException account not authorized' => [
                'bedrock', 400,
                '{"message":"Your AWS account is not authorized to invoke this API operation."}',
                ['x-amzn-ErrorType' => 'ValidationException'],
            ],
            // https://ai.google.dev/gemini-api/docs/api-errors
            'gemini 401 authentication code' => [
                'gemini', 401,
                '{"error":{"code":"authentication","message":"The API key is missing, invalid, or expired."}}',
                [],
            ],
            // ErrorInfo reason on the google.rpc envelope (https://google.aip.dev/193).
            'gemini 400 INVALID_ARGUMENT with API_KEY_INVALID reason' => [
                'gemini', 400,
                '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT","details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"API_KEY_INVALID","domain":"googleapis.com","metadata":{"service":"generativelanguage.googleapis.com"}}]}}',
                [],
            ],
            'gemini 403 PERMISSION_DENIED naming no model' => [
                'gemini', 403,
                '{"error":{"code":403,"message":"Permission denied: Consumer has been suspended.","status":"PERMISSION_DENIED"}}',
                [],
            ],
        ];
    }

    // ── negative controls: request-shaped failures stay plain fatals ──────────

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('plainFatalFixtures')]
    public function test_request_scoped_failures_stay_unclassified(string $provider, int $status, string $body, array $headers): void
    {
        $e = $this->failWith($status, $body, $headers, $provider, 'configured-model');

        $this->assertInstanceOf(GenAiFatalException::class, $e);
        $this->assertNotInstanceOf(GenAiConfigurationException::class, $e);
        $this->assertSame(GenAiFatalException::class, $e::class);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: array<string, string>}>
     */
    public static function plainFatalFixtures(): array
    {
        return [
            'anthropic 400 invalid_request_error about the payload' => [
                'anthropic', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"messages.0.content.0.image.source.base64: image exceeds 5 MB maximum"}}',
                [],
            ],
            // A 400 whose text happens to contain the word "model" is still the
            // request's problem — the model id was accepted.
            'anthropic 400 invalid_request_error mentioning the model' => [
                'anthropic', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"This model does not support assistant message prefill. The conversation must end with a user message."}}',
                [],
            ],
            // The doc example message names no model: it is a Files id, not the
            // configured model.
            'anthropic 404 not_found_error for another resource' => [
                'anthropic', 404,
                '{"type":"error","error":{"type":"not_found_error","message":"The requested resource could not be found."},"request_id":"req_011CSHoEeqs5C35K2UUqR7Fy"}',
                [],
            ],
            'bedrock 400 ValidationException about the payload' => [
                'bedrock', 400,
                '{"message":"The number of documents in the request is greater than the maximum allowed."}',
                ['x-amzn-ErrorType' => 'ValidationException'],
            ],
            'gemini 400 INVALID_ARGUMENT about the payload' => [
                'gemini', 400,
                '{"error":{"code":400,"message":"Unable to submit request because it has an empty contents field.","status":"INVALID_ARGUMENT"}}',
                [],
            ],
            'gemini 400 invalid_request code' => [
                'gemini', 400,
                '{"error":{"code":"invalid_request","message":"The value \'invalid_tool_type_xyz\' is not supported for \'type\' at \'tools[0]\'."}}',
                [],
            ],
            'gemini 404 NOT_FOUND for an uploaded file' => [
                'gemini', 404,
                '{"error":{"code":404,"message":"File files/abc123 is not found.","status":"NOT_FOUND"}}',
                [],
            ],
        ];
    }

    public function test_unknown_provider_keeps_the_old_plain_fatal(): void
    {
        // No forProvider() binding: the classifier has no vocabulary to apply,
        // so behaviour is exactly what it was before this existed.
        $e = $this->failWith(404, '{"type":"error","error":{"type":"not_found_error","message":"model: claude-retired-1"}}');

        $this->assertSame(GenAiFatalException::class, $e::class);
    }

    public function test_401_without_a_provider_is_still_an_authentication_failure(): void
    {
        // 401 is an HTTP-level statement about the credential, so it classifies
        // even with an empty body and no provider bound.
        $e = $this->failWith(401, '');

        $this->assertInstanceOf(GenAiAuthenticationException::class, $e);
        $this->assertNull($e->provider);
        $this->assertSame(401, $e->status);
    }

    // ── negative controls: transient failures keep their own classes ─────────

    public function test_429_remains_a_rate_limit_with_retry_after(): void
    {
        $e = $this->failWith(
            429,
            '{"message":"Too many requests, please wait before trying again."}',
            ['x-amzn-ErrorType' => 'ThrottlingException', 'Retry-After' => '12'],
            'bedrock',
            'anthropic.claude-haiku-4-5-20251001-v1:0',
        );

        $this->assertInstanceOf(GenAiRateLimitException::class, $e);
        $this->assertNotInstanceOf(GenAiConfigurationException::class, $e);
        $this->assertSame(12, $e->retryAfter);
    }

    public function test_503_after_the_retry_budget_remains_a_generic_genai_exception(): void
    {
        $e = $this->failWith(
            503,
            '{"error":{"code":503,"message":"The model is overloaded. Please try again later.","status":"UNAVAILABLE"}}',
            [],
            'gemini',
            'gemini-3.6-flash',
            maxAttempts: 2,
        );

        $this->assertSame(GenAiException::class, $e::class);
    }

    public function test_500_remains_a_generic_genai_exception(): void
    {
        $e = $this->failWith(
            500,
            '{"type":"error","error":{"type":"api_error","message":"Internal server error"}}',
            [],
            'anthropic',
            'claude-sonnet-4-6',
        );

        $this->assertSame(GenAiException::class, $e::class);
    }

    // ── the marker interface is the stable host-facing handle ────────────────

    public function test_both_classes_are_catchable_as_one_configuration_failure(): void
    {
        $caught = [];

        foreach ([
            new GenAiModelUnavailableException('m', 'gemini', 'gemini-3.6-flash'),
            new GenAiAuthenticationException('a', 'gemini', 403),
        ] as $thrown) {
            try {
                throw $thrown;
            } catch (GenAiConfigurationException $e) {
                $caught[] = $e->provider();
            }
        }

        $this->assertSame(['gemini', 'gemini'], $caught);
    }

    public function test_configuration_failures_are_still_fatal_exceptions(): void
    {
        // Backward compatibility: a host that only catches GenAiFatalException
        // keeps catching everything it caught before.
        $this->assertInstanceOf(GenAiFatalException::class, new GenAiModelUnavailableException('m'));
        $this->assertInstanceOf(GenAiFatalException::class, new GenAiAuthenticationException('a'));
    }

    // ── each client binds its own provider and model ─────────────────────────

    public function test_anthropic_client_reports_its_configured_model(): void
    {
        Http::fake(['*' => Http::response('{"type":"error","error":{"type":"not_found_error","message":"model: claude-retired-1"}}', 404)]);

        $client = new AnthropicClient(
            apiKey: 'test-key',
            model: 'claude-retired-1',
            retry: new RetryStrategy(maxAttempts: 1),
        );

        try {
            $client->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);
            $this->fail('Expected GenAiModelUnavailableException');
        } catch (GenAiModelUnavailableException $e) {
            $this->assertSame('anthropic', $e->provider);
            $this->assertSame('claude-retired-1', $e->modelId);
        }
    }

    public function test_bedrock_client_reports_its_configured_model(): void
    {
        Http::fake(['*' => Http::response('{"message":"The provided model identifier is invalid."}', 400, ['x-amzn-ErrorType' => 'ValidationException'])]);

        $client = new BedrockClient(
            apiKey: 'test-key',
            modelId: 'anthropic.claude-typo-v1:0',
            region: 'us-east-1',
            retry: new RetryStrategy(maxAttempts: 1),
        );

        try {
            $client->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);
            $this->fail('Expected GenAiModelUnavailableException');
        } catch (GenAiModelUnavailableException $e) {
            $this->assertSame('bedrock', $e->provider);
            $this->assertSame('anthropic.claude-typo-v1:0', $e->modelId);
        }
    }

    public function test_gemini_client_reports_its_configured_model(): void
    {
        Http::fake(['*' => Http::response('{"error":{"code":404,"message":"models/gemini-retired-pro is not found for API version v1beta, or is not supported for generateContent.","status":"NOT_FOUND"}}', 404)]);

        $client = new GeminiClient(
            apiKey: 'test-key',
            model: 'models/gemini-retired-pro',
            retry: new RetryStrategy(maxAttempts: 1),
        );

        try {
            $client->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);
            $this->fail('Expected GenAiModelUnavailableException');
        } catch (GenAiModelUnavailableException $e) {
            $this->assertSame('gemini', $e->provider);
            // The `models/` prefix is normalised away at construction, so the
            // id reported back is the one the client actually calls with.
            $this->assertSame('gemini-retired-pro', $e->modelId);
        }
    }
}
