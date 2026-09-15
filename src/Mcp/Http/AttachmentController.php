<?php

namespace Bherila\GenAiLaravel\Mcp\Http;

use Bherila\GenAiLaravel\Contracts\AttachmentResolver;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class AttachmentController
{
    public function __construct(private McpQueueService $queue, private AttachmentResolver $resolver) {}

    public function __invoke(Request $http, string $requestId, string $attachmentId): Response
    {
        if (! $http->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired signature.'], 403);
        }
        $context = $http->attributes->get(ExecutionContext::class);
        try {
            $request = $this->queue->findAuthorized($context, $requestId, 'genai:work');
        } catch (\Throwable) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }
        if ($request->status !== McpRequestStatus::Leased || $request->lease_expires_at?->isPast()
            || $request->lease_principal !== $context->principalKey
            || (int) $http->query('attempt') !== $request->attempt_count) {
            return response()->json(['message' => 'Attachment access expired.'], 410);
        }
        $attachment = McpAttachment::query()->where('request_id', $request->id)->whereKey($attachmentId)->first();
        if ($attachment === null) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }
        if ($http->isMethod('HEAD')) {
            return response('', 200, $this->headers($attachment));
        }
        $stream = $this->resolver->readStream($attachment, $context);

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $this->headers($attachment));
    }

    /** @return array<string, string> */
    private function headers(McpAttachment $attachment): array
    {
        $name = str_replace(['"', "\r", "\n"], '', basename($attachment->name));

        return ['Content-Type' => $attachment->mime_type, 'Content-Length' => (string) $attachment->size,
            'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
    }
}
