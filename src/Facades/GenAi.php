<?php

namespace Bherila\GenAiLaravel\Facades;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\ModelInfo;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\Usage;
use Illuminate\Support\Facades\Facade;

/**
 * Facade over the container-bound default GenAiClient.
 *
 * This resolves one client — whatever is bound to the GenAiClient contract — so
 * it is the right tool for an application that uses a single provider. There is
 * deliberately no `GenAi::client('anthropic')`: choosing a provider per call is
 * GenAiClientFactory::make()'s job, and routing it through a facade would hide
 * which credentials a call actually used.
 *
 * @method static string provider()
 * @method static string model()
 * @method static int maxInlineFileBytes(string $mimeType)
 * @method static int|null maxUploadedFileBytes()
 * @method static int|null maxFilesPerMessage()
 * @method static bool supportsFileApi()
 * @method static array<string, mixed> converse(string $system, array<int, array{role: string, content: array<int, ContentBlock>}> $messages, ?ToolConfig $toolConfig = null)
 * @method static string uploadFile(mixed $fileContent, string $mimeType, string $displayName = '')
 * @method static void deleteFile(string $fileRef)
 * @method static array<string, mixed> converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?ToolConfig $toolConfig = null)
 * @method static array<string, mixed> converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, string $system = '', ?ToolConfig $toolConfig = null)
 * @method static string extractText(array<string, mixed> $response)
 * @method static array<int, array{id: string, name: string, input: array<string, mixed>}> extractToolCalls(array<string, mixed> $response)
 * @method static bool checkCredentials()
 * @method static array<int, ModelInfo> listModels()
 * @method static Usage extractUsage(array<string, mixed> $response)
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
