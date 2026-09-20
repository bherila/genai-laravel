<?php

namespace Bherila\GenAiLaravel;

use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;

/**
 * Size accounting for file content, shared by every client.
 *
 * Every published provider limit is expressed in *decoded* bytes, but this
 * package moves files around base64-encoded, so measuring `strlen($base64)`
 * overstates a file by a third and silently under-enforces every limit. These
 * helpers do the arithmetic once, in one place.
 */
final class FileLimits
{
    /**
     * Room left for the JSON scaffolding a payload wraps content in — field
     * names, roles, block types, tool definitions — which is not visible when
     * the allowance is computed from raw material.
     */
    private const REQUEST_ENVELOPE_RESERVE = 65_536;

    /** JSON escaping can double a tab- and newline-heavy extract. */
    private const ESCAPING_FACTOR = 2;

    /**
     * Decoded byte length of a base64 string, computed without allocating the
     * decoded copy — the strings involved are megabytes wide.
     *
     * Whitespace (chunked base64 from `base64_encode(..., true)`-style sources or
     * from a data URI) is discounted, and trailing `=` padding removed, so the
     * result matches strlen(base64_decode($base64)).
     */
    public static function decodedLength(string $base64): int
    {
        $whitespace = substr_count($base64, "\n")
            + substr_count($base64, "\r")
            + substr_count($base64, ' ')
            + substr_count($base64, "\t");

        $padding = 0;
        for ($i = strlen($base64) - 1; $i >= 0 && $padding < 2; $i--) {
            $char = $base64[$i];
            if ($char === '=') {
                $padding++;

                continue;
            }
            if ($char === "\n" || $char === "\r" || $char === ' ' || $char === "\t") {
                continue;
            }
            break;
        }

        $payloadChars = strlen($base64) - $whitespace - $padding;

        return $payloadChars > 0 ? intdiv($payloadChars * 3, 4) : 0;
    }

    /**
     * Byte length of file content supplied to uploadFile(), or null when it is a
     * stream whose size cannot be determined without consuming it.
     *
     * @param  resource|string  $fileContent
     */
    public static function contentLength(mixed $fileContent): ?int
    {
        if (is_string($fileContent)) {
            return strlen($fileContent);
        }

        if (is_resource($fileContent)) {
            $stat = @fstat($fileContent);

            return is_array($stat) && $stat['size'] > 0 ? (int) $stat['size'] : null;
        }

        return null;
    }

    /**
     * Reject a file that exceeds a provider limit before it reaches the wire.
     *
     * @throws GenAiFileTooLargeException
     */
    public static function assertWithin(int $actualBytes, int $limitBytes, string $provider, string $what): void
    {
        if ($actualBytes <= $limitBytes) {
            return;
        }

        throw new GenAiFileTooLargeException(
            sprintf(
                '%s: %s is %s, which exceeds the %s limit for this provider. '
                .'Send it through the File API where one is available, split it, or downscale it.',
                $provider,
                $what,
                self::humanBytes($actualBytes),
                self::humanBytes($limitBytes),
            ),
            actualBytes: $actualBytes,
            limitBytes: $limitBytes,
        );
    }

    /**
     * Reject a serialized request that exceeds the provider's overall ceiling.
     *
     * Measured on the encoded payload rather than estimated from block sizes:
     * JSON escaping expands text unpredictably, and per-block checks cannot see
     * the prompt, the tools or the history sharing the same budget.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws GenAiFileTooLargeException
     */
    public static function assertRequestWithin(array $payload, ?int $limitBytes, string $provider): void
    {
        if ($limitBytes === null) {
            return;
        }

        $encoded = json_encode($payload);
        if ($encoded === false) {
            return;
        }

        self::assertWithin(strlen($encoded), $limitBytes, $provider, 'the complete serialized request');
    }

    /**
     * How many output bytes one document conversion may spend in this request.
     *
     * A conversion ceiling chosen in isolation can emit more text than the
     * provider accepts for the whole request: the extract gets built, the
     * payload assembled, and only then rejected — paying for the work and
     * delivering nothing, not even the partial extract that would have fitted.
     * This derives what is actually left instead, so the conversion truncates
     * itself to the budget and the model still receives usable data.
     *
     * Deliberately pessimistic, because the finished payload does not exist yet
     * to be measured: an envelope is reserved for the JSON structure and field
     * names the payload will add around the content, and what remains is halved
     * because escaping can double a tab- and newline-heavy extract. The exact
     * check on the finished payload still runs — this only stops it from being
     * the first thing that notices.
     *
     * @param  int  $committedBytes  What the rest of the request already costs.
     * @param  int  $conversions  Conversions sharing the budget; each gets an equal share.
     * @return int|null Null when the provider documents no request ceiling.
     */
    public static function conversionOutputAllowance(?int $requestBudgetBytes, int $committedBytes, int $conversions = 1): ?int
    {
        if ($requestBudgetBytes === null || $conversions < 1) {
            return null;
        }

        $remaining = $requestBudgetBytes - $committedBytes - self::REQUEST_ENVELOPE_RESERVE;

        return max(0, intdiv($remaining, self::ESCAPING_FACTOR * $conversions));
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0
            ? $bytes.' B'
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').' '.$units[$unit];
    }
}
