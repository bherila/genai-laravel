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

            return is_array($stat) && isset($stat['size']) && $stat['size'] > 0 ? (int) $stat['size'] : null;
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
