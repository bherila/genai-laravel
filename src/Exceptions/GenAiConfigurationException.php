<?php

namespace Bherila\GenAiLaravel\Exceptions;

use Throwable;

/**
 * Marker for permanent failures that only a configuration change can clear:
 * the configured model is wrong, or the credential is.
 *
 * A host application has three distinct outcomes to tell apart when a provider
 * call fails — retry later (transient), give up on this request (the payload was
 * bad), and stop and page someone (nothing in this deployment will work until a
 * human changes a setting). The first two already had exceptions; this interface
 * is the third, so a caller can write one `catch` instead of string-matching a
 * provider's error prose.
 *
 * Implemented by exactly `GenAiModelUnavailableException` and
 * `GenAiAuthenticationException`. Both also extend `GenAiFatalException`, so
 * existing `catch (GenAiFatalException)` handlers keep catching them.
 */
interface GenAiConfigurationException extends Throwable
{
    /**
     * Provider slug the failure came from (`anthropic`, `bedrock`, `gemini`),
     * or null when the strategy was used without one.
     */
    public function provider(): ?string;
}
