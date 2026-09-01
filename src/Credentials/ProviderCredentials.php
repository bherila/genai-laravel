<?php

namespace Bherila\GenAiLaravel\Credentials;

/**
 * Credentials supplied at call time rather than read from config.
 *
 * The config file long advertised per-user keys through
 * `GenAiClientFactory::make()`, but make() only ever took a provider name and
 * always read credentials from config, so there was no way to do it. These
 * value objects are that way — and the reason to reach for this package over a
 * config-driven SDK when keys belong to tenants rather than to the deployment.
 */
interface ProviderCredentials
{
    /** The provider these credentials belong to ("gemini", "bedrock", "anthropic"). */
    public function provider(): string;
}
