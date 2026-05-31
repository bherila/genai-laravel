<?php

namespace Bherila\GenAiLaravel\Instrumentation;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\ToolConfig;
use Sentry\Tracing\SpanContext;

final class SentryGenAiTracer
{
    /**
     * @param  list<array{role: string, content: list<ContentBlock>}>  $inputMessages
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public static function trace(
        GenAiClient $client,
        array $inputMessages,
        string $system,
        ?ToolConfig $toolConfig,
        callable $callback,
    ): array {
        if (! self::canTrace()) {
            return $callback();
        }

        $operation = 'chat';
        $context = SpanContext::make()
            ->setOp("gen_ai.{$operation}")
            ->setDescription("{$operation} ".$client->model())
            ->setData(self::baseSpanData($client, $operation));

        if (self::recordContent()) {
            $context->setData(array_merge(
                $context->getData(),
                self::contentSpanData($inputMessages, $system, $toolConfig),
            ));
        }

        return \Sentry\trace(function ($scope) use ($callback, $client): array {
            $response = $callback();
            $span = method_exists($scope, 'getSpan') ? $scope->getSpan() : null;

            if ($span !== null) {
                $span->setData(self::responseSpanData($client, $response));
            }

            return $response;
        }, $context);
    }

    private static function canTrace(): bool
    {
        return self::configBool('genai.instrumentation.sentry.enabled', true)
            && function_exists('\Sentry\trace')
            && class_exists('\Sentry\Tracing\SpanContext');
    }

    /** @return array<string, string> */
    private static function baseSpanData(GenAiClient $client, string $operation): array
    {
        return array_filter([
            'gen_ai.request.model' => $client->model(),
            'gen_ai.operation.name' => $operation,
            'gen_ai.agent.name' => self::configValue('genai.instrumentation.sentry.agent_name'),
            'gen_ai.conversation.id' => self::configValue('genai.instrumentation.sentry.conversation_id'),
            'gen_ai.provider.name' => $client->provider(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  list<array{role: string, content: list<ContentBlock>}>  $inputMessages
     * @return array<string, string>
     */
    private static function contentSpanData(array $inputMessages, string $system, ?ToolConfig $toolConfig): array
    {
        $data = [
            'gen_ai.input.messages' => json_encode(self::formatMessages($inputMessages), JSON_THROW_ON_ERROR),
        ];

        if ($system !== '') {
            $data['gen_ai.system_instructions'] = $system;
        }

        if ($toolConfig !== null) {
            $data['gen_ai.tool.definitions'] = json_encode(self::formatTools($toolConfig), JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    /** @return array<string, string|int> */
    private static function responseSpanData(GenAiClient $client, array $response): array
    {
        $usage = $client->extractUsage($response);
        $inputTotal = $usage->inputTokens + $usage->cacheReadInputTokens + $usage->cacheCreationInputTokens;
        $data = [
            'gen_ai.usage.input_tokens' => $inputTotal,
            'gen_ai.usage.output_tokens' => $usage->outputTokens,
            'gen_ai.usage.total_tokens' => $usage->totalTokens > 0 ? $usage->totalTokens : $inputTotal + $usage->outputTokens,
        ];

        if ($usage->cacheReadInputTokens > 0) {
            $data['gen_ai.usage.input_tokens.cached'] = $usage->cacheReadInputTokens;
        }

        if ($usage->cacheCreationInputTokens > 0) {
            $data['gen_ai.usage.input_tokens.cache_write'] = $usage->cacheCreationInputTokens;
        }

        if (self::recordContent()) {
            $data['gen_ai.output.messages'] = json_encode([
                [
                    'role' => 'assistant',
                    'parts' => [['type' => 'text', 'content' => $client->extractText($response)]],
                ],
            ], JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    private static function recordContent(): bool
    {
        return self::configBool('genai.instrumentation.sentry.record_content', false);
    }

    private static function configBool(string $key, bool $default): bool
    {
        return (bool) self::configValue($key, $default);
    }

    private static function configValue(string $key, mixed $default = null): mixed
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @param  list<array{role: string, content: list<ContentBlock>}>  $messages
     * @return list<array{role: string, parts: list<array{type: string, content: string}>}>
     */
    private static function formatMessages(array $messages): array
    {
        return array_map(fn (array $message) => [
            'role' => $message['role'],
            'parts' => array_map(fn (ContentBlock $block) => [
                'type' => $block->type,
                'content' => $block->type === 'text'
                    ? (string) $block->text
                    : '[document: '.($block->mimeType ?? 'application/octet-stream').']',
            ], $message['content']),
        ], $messages);
    }

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    private static function formatTools(ToolConfig $toolConfig): array
    {
        return array_map(fn ($tool) => [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $tool->inputSchema->toArray(),
        ], $toolConfig->tools);
    }
}
