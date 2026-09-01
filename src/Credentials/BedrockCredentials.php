<?php

namespace Bherila\GenAiLaravel\Credentials;

/**
 * Per-request AWS Bedrock credentials.
 *
 * `apiKey` is the bearer token itself, not an AWS access key ID — this package
 * does not use SigV4. Region matters as much as the key here, because a Bedrock
 * model ID is only valid in the regions its inference profile covers.
 */
final class BedrockCredentials implements ProviderCredentials
{
    /**
     * @param  string  $apiKey  Bearer token sent as `Authorization: Bearer …`.
     * @param  string|null  $region  Region override; null keeps the configured region.
     * @param  string|null  $sessionToken  Optional STS session token for temporary credentials.
     * @param  string|null  $model  Model ID or inference profile override; null keeps the configured one.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly ?string $region = null,
        public readonly ?string $sessionToken = null,
        public readonly ?string $model = null,
    ) {}

    public function provider(): string
    {
        return 'bedrock';
    }
}
