<?php

namespace Bherila\GenAiLaravel\Facades;

use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string provider()
 * @method static int maxFileBytes()
 * @method static array converse(array $system, array $messages, ?array $toolConfig = null)
 * @method static string|null uploadFile(mixed $fileContent, string $mimeType, string $displayName = '')
 * @method static void deleteFile(string $fileRef)
 * @method static array converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?array $toolConfig = null)
 * @method static array converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, array $system = [], ?array $toolConfig = null)
 * @method static string extractText(array $response)
 * @method static list<array{name: string, input: array}> extractToolCalls(array $response)
 *
 * @see GenAiClient
 */
class GenAi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GenAiClient::class;
    }
}
