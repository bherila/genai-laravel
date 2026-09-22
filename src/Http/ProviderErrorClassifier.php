<?php

namespace Bherila\GenAiLaravel\Http;

use Bherila\GenAiLaravel\Exceptions\GenAiAuthenticationException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiModelUnavailableException;

/**
 * The one place that decides whether a non-retryable provider response is a
 * *configuration* failure (someone must change a setting) or an ordinary
 * per-request rejection.
 *
 * Without this, every host application ends up matching provider prose by hand —
 * "The provided model identifier is invalid." for Bedrock, a `not_found_error`
 * body for Anthropic — and each copy drifts the next time a provider reworks its
 * wording. RetryStrategy calls this for all three clients, so there is exactly
 * one copy to correct.
 *
 * Conservative by construction: anything it cannot positively attribute to the
 * configured model or the credential stays a plain `GenAiFatalException` (null
 * return). A payload 400 — bad content, too many documents, a malformed tool
 * schema — must never be reported as a configuration failure, because the fix
 * is in the request rather than in the deployment.
 *
 * Every rule below cites the provider documentation it was built from. Where
 * only the wire *wording* is observed rather than quoted by a doc, the comment
 * says so.
 */
final class ProviderErrorClassifier
{
    /**
     * Provider slugs this classifier knows. Anything else falls through to the
     * status-only floor below.
     */
    public const KNOWN_PROVIDERS = ['anthropic', 'bedrock', 'gemini'];

    /**
     * Classify one failed response.
     *
     * @param  int  $status  HTTP status the provider answered with.
     * @param  string  $body  Raw response body.
     * @param  string  $message  Message for the exception — the caller's request
     *                           context plus the provider's own text, unchanged
     *                           from what a plain GenAiFatalException would carry.
     * @param  string|null  $provider  Provider slug, when the client set one.
     * @param  string|null  $modelId  Configured model id, when the client set one.
     * @param  string|null  $errorTypeHeader  `x-amzn-errortype`, for AWS responses.
     * @return GenAiFatalException|null Null when the response is not attributable
     *                                  to configuration; the caller then throws
     *                                  its usual exception.
     */
    public static function classify(
        int $status,
        string $body,
        string $message,
        ?string $provider = null,
        ?string $modelId = null,
        ?string $errorTypeHeader = null,
    ): ?GenAiFatalException {
        // 429 and 5xx are handled by the caller and must keep their own classes:
        // a throttle is transient and a server error is retryable, neither is a
        // setting anyone can change.
        if (! in_array($status, RetryStrategy::FATAL_STATUSES, true)) {
            return null;
        }

        $payload = self::decode($body);

        $classified = match ($provider) {
            'anthropic' => self::classifyAnthropic($status, $payload, $message, $provider, $modelId),
            'bedrock' => self::classifyBedrock($status, $payload, $message, $provider, $modelId, $errorTypeHeader),
            'gemini' => self::classifyGemini($status, $payload, $message, $provider, $modelId),
            default => null,
        };

        if ($classified !== null) {
            return $classified;
        }

        // Status-only floor. 401 is an HTTP-level statement that the request
        // carried no acceptable credential, and all three providers document it
        // that way, so it is an authentication failure even when the body is
        // empty or unparseable.
        if ($status === 401) {
            return new GenAiAuthenticationException($message, $provider, $status);
        }

        return null;
    }

    /**
     * Anthropic Messages API.
     *
     * Body shape and error types are documented at
     * https://platform.claude.com/docs/en/api/errors — every error is
     * `{"type": "error", "error": {"type": ..., "message": ...}}`, with
     * 400 `invalid_request_error`, 401 `authentication_error`,
     * 403 `permission_error` ("Your API key does not have permission to use the
     * specified resource") and 404 `not_found_error`.
     *
     * The model case rides on `not_found_error`: an unknown or retired model id
     * comes back as a 404 whose message names the model (observed:
     * `{"type":"not_found_error","message":"model: <id>"}`); the doc's own
     * example message, "The requested resource could not be found.", names no
     * model and stays a plain fatal, as does a 404 for a Files API id.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function classifyAnthropic(
        int $status,
        array $payload,
        string $message,
        string $provider,
        ?string $modelId,
    ): ?GenAiFatalException {
        $error = self::subArray($payload, 'error');
        $type = self::stringField($error, 'type');
        $detail = self::stringField($error, 'message');

        return match ($type) {
            'authentication_error' => new GenAiAuthenticationException($message, $provider, $status),
            'permission_error' => self::namesModel($detail, $modelId, $provider)
                ? new GenAiModelUnavailableException($message, $provider, $modelId)
                : new GenAiAuthenticationException($message, $provider, $status),
            'not_found_error' => self::namesModel($detail, $modelId, $provider)
                ? new GenAiModelUnavailableException($message, $provider, $modelId)
                : null,
            // invalid_request_error (400) is the payload bucket — prefill on a
            // model that rejects it, a malformed tool schema, too many blocks.
            default => null,
        };
    }

    /**
     * Amazon Bedrock Converse / control plane.
     *
     * The operation's error shapes are documented at
     * https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_Converse.html
     * (ValidationException 400, AccessDeniedException 403,
     * ResourceNotFoundException 404, ThrottlingException 429) and the common set
     * at https://docs.aws.amazon.com/bedrock/latest/APIReference/CommonErrors.html
     * (NotAuthorized 401, UnrecognizedClientException / ExpiredTokenException /
     * IncompleteSignature / OptInRequired 403).
     *
     * The shape name arrives in `x-amzn-errortype` or the body's `__type` /
     * `code`, possibly decorated; restJson1 says to keep only what is before the
     * first `:` and after the first `#`:
     * https://smithy.io/2.0/aws/protocols/aws-restjson1-protocol.html#operation-error-serialization
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function classifyBedrock(
        int $status,
        array $payload,
        string $message,
        string $provider,
        ?string $modelId,
        ?string $errorTypeHeader,
    ): ?GenAiFatalException {
        $type = self::awsErrorType($errorTypeHeader, $payload);
        $detail = self::stringField($payload, 'message') ?: self::stringField($payload, 'Message');

        // Model-identifier rejections are recognised by message first, because
        // they arrive under two different shape names for the same cause. All
        // three texts are quoted by AWS:
        //   "The provided model identifier is invalid." (ValidationException)
        //   "Could not resolve the foundation model from the provided model
        //    identifier." (ResourceNotFoundException)
        //   "Invocation of model ID <id> with on-demand throughput isn't
        //    supported. Retry your request with the ID or ARN of an inference
        //    profile that contains this model." (ValidationException)
        // https://repost.aws/knowledge-center/bedrock-validation-exception-errors
        // https://repost.aws/knowledge-center/bedrock-invokemodel-api-error
        if (self::matchesAny($detail, [
            '/provided model identifier is invalid/i',
            '/could not resolve the foundation model/i',
            // Matched on the stable half of the sentence: the apostrophe in
            // "isn't" arrives as ASCII or as U+2019 depending on the caller's
            // encoding, and a pattern that spans it is a pattern that breaks.
            '/with on-demand throughput/i',
        ])) {
            return new GenAiModelUnavailableException($message, $provider, $modelId);
        }

        return match ($type) {
            // 403 for the account's own permissions. When the text says the
            // account cannot reach *this model* it is the model that must
            // change, not the key — the marketplace-agreement cases are
            // described in exactly those words ("Your account is not authorized
            // to access this model"):
            // https://docs.aws.amazon.com/bedrock/latest/userguide/troubleshooting-api-error-codes.html
            'AccessDeniedException' => self::matchesAny($detail, [
                '/access to (the )?model/i',
                '/authorized to access this model/i',
            ])
                ? new GenAiModelUnavailableException($message, $provider, $modelId)
                : new GenAiAuthenticationException($message, $provider, $status),

            'NotAuthorized',
            'UnrecognizedClientException',
            'InvalidClientTokenId',
            'ExpiredTokenException',
            'IncompleteSignature',
            'InvalidSignatureException',
            'MissingAuthenticationToken',
            'MissingAuthenticationTokenException' => new GenAiAuthenticationException($message, $provider, $status),

            // "Your AWS account is not authorized to invoke this API operation."
            // arrives as a 400 ValidationException rather than a 403:
            // https://repost.aws/knowledge-center/bedrock-validation-exception-errors
            'ValidationException' => self::matchesAny($detail, ['/not authorized to invoke this api/i'])
                ? new GenAiAuthenticationException($message, $provider, $status)
                : null,

            // Everything else — a ValidationException about the payload, a
            // ResourceNotFoundException for some other resource — is not
            // attributable to configuration from the shape name alone.
            default => null,
        };
    }

    /**
     * Google Gemini (generativelanguage).
     *
     * Two body shapes are in circulation and both are handled:
     *
     * 1. The current documented envelope, `{"error": {"code": "<string>",
     *    "message": ...}}`, whose codes include `authentication` (401),
     *    `permission_denied` (403), `not_found` (404) and `model_not_found`
     *    (404): https://ai.google.dev/gemini-api/docs/api-errors
     * 2. The google.rpc.Status envelope the v1beta endpoints have always
     *    returned, `{"error": {"code": <int>, "message": ..., "status":
     *    "<CANONICAL>", "details": [{"@type": ".../google.rpc.ErrorInfo",
     *    "reason": ...}]}}`: https://google.aip.dev/193
     *
     * An unknown model id surfaces as a NOT_FOUND whose message names the model
     * resource (observed: "models/<id> is not found for API version v1beta, or
     * is not supported for generateContent."), and a rejected API key as an
     * INVALID_ARGUMENT carrying the ErrorInfo reason `API_KEY_INVALID`.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function classifyGemini(
        int $status,
        array $payload,
        string $message,
        string $provider,
        ?string $modelId,
    ): ?GenAiFatalException {
        $error = self::subArray($payload, 'error');
        $detail = self::stringField($error, 'message');
        $code = $error['code'] ?? null;

        if (is_string($code)) {
            return match ($code) {
                'model_not_found' => new GenAiModelUnavailableException($message, $provider, $modelId),
                'authentication' => new GenAiAuthenticationException($message, $provider, $status),
                'permission_denied' => self::namesModel($detail, $modelId, $provider)
                    ? new GenAiModelUnavailableException($message, $provider, $modelId)
                    : new GenAiAuthenticationException($message, $provider, $status),
                'not_found' => self::namesModel($detail, $modelId, $provider)
                    ? new GenAiModelUnavailableException($message, $provider, $modelId)
                    : null,
                // invalid_request / failed_precondition / parameter_unknown are
                // the request's problem, not the deployment's.
                default => null,
            };
        }

        return match (self::stringField($error, 'status')) {
            'UNAUTHENTICATED' => new GenAiAuthenticationException($message, $provider, $status),
            'PERMISSION_DENIED' => self::namesModel($detail, $modelId, $provider)
                ? new GenAiModelUnavailableException($message, $provider, $modelId)
                : new GenAiAuthenticationException($message, $provider, $status),
            'NOT_FOUND' => self::namesModel($detail, $modelId, $provider)
                ? new GenAiModelUnavailableException($message, $provider, $modelId)
                : null,
            // INVALID_ARGUMENT is overwhelmingly a payload error; the one
            // credential case is the rejected key, which identifies itself
            // either by ErrorInfo reason or by its fixed message.
            'INVALID_ARGUMENT' => self::googleErrorReason($error) === 'API_KEY_INVALID'
                || self::matchesAny($detail, ['/api key not valid/i'])
                ? new GenAiAuthenticationException($message, $provider, $status)
                : null,
            default => null,
        };
    }

    /**
     * Does this provider text point at the configured model rather than some
     * other resource? Used to keep file-not-found and generic permission
     * messages out of the model bucket.
     *
     * The bound provider and model id ride along on *every* call a client makes
     * through its RetryStrategy, including the catalog calls —
     * `AnthropicClient::listModels()` hits `GET /v1/models` and
     * `GeminiClient::listModels()` hits `GET /v1beta/models`. So a 403 from a
     * listing ("your API key does not have permission to list models", or a
     * permission/method name that merely contains "ListModels") would, on a bare
     * `models?` word match, be reported as the configured model being
     * unavailable — for a model the request never mentioned, when the thing to
     * fix is the credential.
     *
     * Only two things count as attribution:
     *  - the configured id appears in the text as a whole token, or
     *  - the text carries the provider's own single-model resource form:
     *    Anthropic's observed `model: <id>` 404 body, or Google's `models/<id>`
     *    resource name (https://ai.google.dev/api/models). A bare "model" /
     *    "models", a method name ending in `ListModels`, or a collection path
     *    with no id after it are not that form.
     */
    private static function namesModel(string $detail, ?string $modelId, string $provider): bool
    {
        if ($detail === '') {
            return false;
        }

        if ($modelId !== null && $modelId !== '' && self::mentionsIdentifier($detail, $modelId)) {
            return true;
        }

        return match ($provider) {
            // Observed wire text for an unknown Anthropic model id:
            // {"type":"not_found_error","message":"model: <id>"}.
            'anthropic' => self::matchesAny($detail, ['/\bmodel:\s*\S/i']),
            // The `models/<id>` resource name names exactly one model; the bare
            // collection `models` (no id) does not.
            'gemini' => self::matchesAny($detail, ['#\bmodels/[A-Za-z0-9][A-Za-z0-9._-]*#i']),
            default => false,
        };
    }

    /**
     * Whether the configured id appears as a whole token rather than as a
     * fragment of a longer word: a short id must not be found inside an
     * unrelated word, which a plain substring test would do.
     */
    private static function mentionsIdentifier(string $subject, string $needle): bool
    {
        return preg_match('/(?<![A-Za-z0-9])'.preg_quote($needle, '/').'(?![A-Za-z0-9])/', $subject) === 1;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matchesAny(string $subject, array $patterns): bool
    {
        if ($subject === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitised AWS error shape name from the header or the body, per the
     * restJson1 rules (drop anything from the first `:`, keep what follows the
     * first `#`).
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function awsErrorType(?string $header, array $payload): string
    {
        $raw = $header !== null && $header !== ''
            ? $header
            : (self::stringField($payload, '__type') ?: self::stringField($payload, 'code'));

        if ($raw === '') {
            return '';
        }

        $colon = strpos($raw, ':');
        if ($colon !== false) {
            $raw = substr($raw, 0, $colon);
        }

        $hash = strpos($raw, '#');
        if ($hash !== false) {
            $raw = substr($raw, $hash + 1);
        }

        return $raw;
    }

    /**
     * `reason` from the first google.rpc.ErrorInfo in `error.details`.
     *
     * @param  array<array-key, mixed>  $error
     */
    private static function googleErrorReason(array $error): string
    {
        $details = $error['details'] ?? null;
        if (! is_array($details)) {
            return '';
        }

        foreach ($details as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $reason = self::stringField($entry, 'reason');
            if ($reason !== '') {
                return $reason;
            }
        }

        return '';
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function subArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
