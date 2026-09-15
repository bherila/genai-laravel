<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestCancelled;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestClaimed;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestCompleted;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestExpired;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestFailed;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestQueued;
use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;
use Bherila\GenAiLaravel\Mcp\Models\McpClaimReceipt;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final readonly class McpQueueService
{
    public function __construct(
        private DatabaseManager $db,
        private MailboxAccessResolver $access,
        private SubmissionSchema $schemas,
        private LeaseTokenFactory $leaseTokens,
    ) {}

    public function enqueue(McpMailbox|string $mailbox, GenAiRequestPayload $payload, ?EnqueueOptions $options = null): McpRequest
    {
        $mailbox = is_string($mailbox) ? McpMailbox::query()->findOrFail($mailbox) : $mailbox;
        if (! $mailbox->enabled) {
            throw new McpQueueException('Mailbox is disabled.', 409);
        }
        $options ??= new EnqueueOptions(maxAttempts: (int) config('genai.mcp.max_attempts', 3));
        if (! preg_match('/^[A-Za-z0-9._-]{1,80}$/', $options->queue)) {
            throw new McpQueueException('Queue name is invalid.', 422);
        }
        if ($options->idempotencyKey !== null && ($options->idempotencyKey === '' || strlen($options->idempotencyKey) > 191)) {
            throw new McpQueueException('Enqueue idempotency key is invalid.', 422);
        }
        if ($options->priority < -2147483648 || $options->priority > 2147483647) {
            throw new McpQueueException('Priority is outside the supported integer range.', 422);
        }
        if ($options->maxAttempts < 1 || $options->maxAttempts > 100) {
            throw new McpQueueException('maxAttempts must be between 1 and 100.', 422);
        }
        if ($options->expiresAt !== null && $options->expiresAt <= ($options->availableAt ?? now())) {
            throw new McpQueueException('expiresAt must be later than availableAt.', 422);
        }

        $raw = $payload->toArray();
        foreach ($raw['tools'] as $tool) {
            $this->schemas->assertPortable($tool['input_schema']);
        }
        $this->assertPayloadBounds($raw, $options->metadata);
        $enqueueHash = hash('sha256', $this->canonicalJson([
            'payload' => $raw, 'queue' => $options->queue, 'priority' => $options->priority,
            'available_at' => $options->availableAt?->format(DATE_ATOM), 'expires_at' => $options->expiresAt?->format(DATE_ATOM),
            'max_attempts' => $options->maxAttempts, 'metadata' => $options->metadata,
        ]));

        $requestId = (string) Str::uuid();
        try {
            return $this->db->connection()->transaction(function () use ($mailbox, $raw, $options, $enqueueHash, $requestId): McpRequest {
                $mailbox = McpMailbox::query()->lockForUpdate()->find($mailbox->id);
                if ($mailbox === null) {
                    throw new McpQueueException('Mailbox not found.', 404);
                }
                if (! $mailbox->enabled) {
                    throw new McpQueueException('Mailbox is disabled.', 409);
                }
                if ($options->idempotencyKey !== null) {
                    $existing = McpRequest::query()->where('mailbox_id', $mailbox->id)
                        ->where('idempotency_key', $options->idempotencyKey)->lockForUpdate()->first();
                    if ($existing !== null) {
                        if (! hash_equals((string) $existing->enqueue_hash, $enqueueHash)) {
                            throw new McpQueueException('Idempotency key was already used for different work.', 409);
                        }

                        return $existing;
                    }
                }

                $request = McpRequest::query()->create([
                    'id' => $requestId, 'mailbox_id' => $mailbox->id, 'queue' => $options->queue,
                    'status' => McpRequestStatus::Pending, 'priority' => $options->priority,
                    'payload' => [], 'metadata' => $options->metadata,
                    'idempotency_key' => $options->idempotencyKey,
                    'enqueue_hash' => $enqueueHash,
                    'available_at' => $options->availableAt ?? now(), 'expires_at' => $options->expiresAt,
                    'max_attempts' => $options->maxAttempts,
                ]);
                $request->payload = $this->materializeAttachments($request, $raw);
                $request->save();
                $this->db->connection()->afterCommit(fn () => event(new McpRequestQueued($request->id)));

                return $request->fresh(['attachments']);
            });
        } catch (\Throwable $exception) {
            Storage::disk((string) config('genai.mcp.attachments.disk', 'local'))->deleteDirectory('genai-mcp/'.$requestId);
            throw $exception;
        }
    }

    /** @return array<string, int> */
    public function status(ExecutionContext $context, ?string $queue = null): array
    {
        $this->assertQueueFilter($queue);

        return $this->db->connection()->transaction(function () use ($context, $queue): array {
            $this->expireRequests($context, $queue);
            $counts = collect(McpRequestStatus::cases())->mapWithKeys(fn ($case) => [$case->value => 0])->all();
            McpRequest::query()->with('mailbox')->whereIn('mailbox_id', $context->mailboxIds)
                ->when($queue !== null, fn ($query) => $query->where('queue', $queue))
                ->lazyById()->each(function (McpRequest $request) use ($context, &$counts): void {
                    if ($this->access->authorize($context, $request->mailbox, 'genai:read', $request)) {
                        $counts[$request->status->value]++;
                    }
                });

            return $counts;
        });
    }

    /** @return array<string, mixed>|null */
    public function claim(ExecutionContext $context, ?string $queue = null, ?string $idempotencyKey = null): ?array
    {
        $this->assertQueueFilter($queue);
        if ($idempotencyKey !== null && (strlen($idempotencyKey) > 191 || $idempotencyKey === '')) {
            throw new McpQueueException('Claim idempotency key is invalid.', 422);
        }

        return $this->db->connection()->transaction(function () use ($context, $queue, $idempotencyKey): ?array {
            $this->expireRequests($context, $queue);
            $this->failExhaustedLeases($context, $queue);
            if ($idempotencyKey !== null) {
                $receipt = McpClaimReceipt::query()->whereIn('mailbox_id', $context->mailboxIds)
                    ->where('principal_key', $context->principalKey)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($receipt !== null && $receipt->expires_at->isFuture()) {
                    $request = McpRequest::query()->with(['attachments', 'mailbox'])->find($receipt->request_id);
                    if ($request?->status === McpRequestStatus::Leased && $request->lease_expires_at?->isFuture()
                        && $this->access->authorize($context, $request->mailbox, 'genai:work', $request)) {
                        return $this->envelope($request, $this->leaseTokens->forReceipt($receipt));
                    }
                }
                $receipt?->delete();
            }

            $request = null;
            for ($page = 1; $request === null; $page++) {
                $candidates = McpRequest::query()->with(['mailbox', 'attachments'])
                    ->whereIn('mailbox_id', $context->mailboxIds)
                    ->when($queue !== null, fn ($q) => $q->where('queue', $queue))
                    ->where('available_at', '<=', now())
                    ->where(fn ($q) => $q->where('status', McpRequestStatus::Pending->value)
                        ->orWhere(fn ($q) => $q->where('status', McpRequestStatus::Leased->value)->where('lease_expires_at', '<=', now())))
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->whereColumn('attempt_count', '<', 'max_attempts')
                    ->orderByDesc('priority')->orderBy('available_at')->orderBy('created_at')->orderBy('id')
                    ->lockForUpdate()->forPage($page, 25)->get();
                $request = $candidates->first(fn (McpRequest $item): bool => $this->access->authorize($context, $item->mailbox, 'genai:work', $item));
                if ($candidates->count() < 25) {
                    break;
                }
            }
            if ($request === null) {
                return null;
            }

            $receipt = null;
            if ($idempotencyKey !== null) {
                $receipt = new McpClaimReceipt([
                    'id' => (string) Str::uuid(), 'request_id' => $request->id, 'mailbox_id' => $request->mailbox_id,
                    'principal_key' => $context->principalKey, 'idempotency_key' => $idempotencyKey,
                ]);
            }
            $plain = $receipt === null ? $this->leaseTokens->random() : $this->leaseTokens->forReceipt($receipt);
            $leaseSeconds = (int) config('genai.mcp.lease.seconds', 900);
            $leaseExpiresAt = now()->addSeconds($leaseSeconds);
            if ($request->expires_at !== null && $leaseExpiresAt->greaterThan($request->expires_at)) {
                $leaseExpiresAt = $request->expires_at;
            }
            $request->forceFill([
                'status' => McpRequestStatus::Leased, 'attempt_count' => $request->attempt_count + 1,
                'leased_at' => now(), 'lease_expires_at' => $leaseExpiresAt,
                'lease_token_hash' => hash('sha256', $plain), 'lease_principal' => $context->principalKey,
            ])->save();
            if ($idempotencyKey !== null) {
                $receipt->expires_at = $request->lease_expires_at;
                $receipt->save();
            }
            $this->db->connection()->afterCommit(fn () => event(new McpRequestClaimed($request->id)));

            return $this->envelope($request, $plain);
        });
    }

    /** @return array<string, mixed> */
    public function renew(ExecutionContext $context, string $requestId, string $leaseToken): array
    {
        return $this->db->connection()->transaction(function () use ($context, $requestId, $leaseToken): array {
            $request = $this->leasedRequest($context, $requestId, $leaseToken);
            $maximum = $request->leased_at->addSeconds((int) config('genai.mcp.lease.max_total_seconds', 3600));
            $next = now()->addSeconds((int) config('genai.mcp.lease.seconds', 900));
            if ($next->greaterThan($maximum)) {
                $next = $maximum;
            }
            if ($request->expires_at !== null && $next->greaterThan($request->expires_at)) {
                $next = $request->expires_at;
            }
            if ($next->isPast()) {
                throw new McpQueueException('Lease can no longer be renewed.', 410);
            }
            $request->lease_expires_at = $next;
            $request->save();

            return $this->envelope($request->load('attachments'), $leaseToken);
        });
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $executor
     * @return array<string, mixed>
     */
    public function complete(ExecutionContext $context, string $requestId, string $leaseToken, array $response, array $executor = []): array
    {
        if (array_diff(array_keys($response), ['text', 'tool_calls']) !== []) {
            throw new McpQueueException('Unknown response fields are not allowed.', 422);
        }
        if (array_diff(array_keys($executor), ['client', 'model']) !== []) {
            throw new McpQueueException('Unknown executor fields are not allowed.', 422);
        }
        foreach ($executor as $value) {
            if (! is_string($value)) {
                throw new McpQueueException('Executor metadata values must be strings.', 422);
            }
        }
        $response = ['text' => $response['text'] ?? '', 'tool_calls' => $response['tool_calls'] ?? []];
        $canonical = $this->canonicalJson(['response' => $response, 'executor' => $executor]);
        $hash = hash('sha256', $canonical);

        return $this->db->connection()->transaction(function () use ($context, $requestId, $leaseToken, $response, $executor, $hash): array {
            $request = $this->authorizedRequest($context, $requestId, 'genai:work', true);
            if ($request->status === McpRequestStatus::Completed) {
                if (hash_equals((string) $request->completion_hash, $hash)
                    && hash_equals((string) $request->completion_lease_hash, hash('sha256', $leaseToken))
                    && $request->completion_principal === $context->principalKey) {
                    return $this->receipt($request);
                }
                throw new McpQueueException('Request was already completed with different data.', 409);
            }
            $this->assertLiveLease($request, $context, $leaseToken);
            $this->schemas->validate($response, $request->payload);
            $receiptId = (string) Str::uuid();
            $result = ['text' => $response['text'], 'tool_calls' => $response['tool_calls'], 'executor' => $this->sanitizeExecutor($executor)];
            $request->forceFill([
                'status' => McpRequestStatus::Completed, 'result' => $result, 'completed_at' => now(),
                'completion_hash' => $hash, 'completion_lease_hash' => hash('sha256', $leaseToken),
                'completion_principal' => $context->principalKey, 'completion_receipt_id' => $receiptId,
                'lease_token_hash' => null, 'lease_expires_at' => null,
            ])->save();
            McpDelivery::query()->create([
                'request_id' => $request->id, 'type' => 'completed',
                'payload' => ['receipt_id' => $receiptId, 'result' => $result], 'available_at' => now(),
            ]);
            $this->db->connection()->afterCommit(fn () => event(new McpRequestCompleted($request->id, $receiptId)));

            return $this->receipt($request);
        });
    }

    /** @return array<string, mixed> */
    public function fail(ExecutionContext $context, string $requestId, string $leaseToken, string $code, string $message, bool $retryable): array
    {
        return $this->db->connection()->transaction(function () use ($context, $requestId, $leaseToken, $code, $message, $retryable): array {
            $request = $this->leasedRequest($context, $requestId, $leaseToken);
            $terminal = ! $retryable || $request->attempt_count >= $request->max_attempts;
            $error = ['code' => Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '_', $code) ?: 'executor_error', 80, ''), 'message' => Str::limit($message, 1000)];
            $request->forceFill([
                'status' => $terminal ? McpRequestStatus::Failed : McpRequestStatus::Pending,
                'error' => $error, 'failed_at' => $terminal ? now() : null,
                'available_at' => $terminal ? $request->available_at : now()->addSeconds($this->backoffSeconds($request->attempt_count)),
                'lease_token_hash' => null, 'lease_expires_at' => null, 'lease_principal' => null,
            ])->save();
            if ($terminal) {
                McpDelivery::query()->create(['request_id' => $request->id, 'type' => 'failed', 'payload' => ['error' => $error], 'available_at' => now()]);
            }
            $this->db->connection()->afterCommit(fn () => event(new McpRequestFailed($request->id, $terminal)));

            return ['request_id' => $request->id, 'status' => $request->status->value, 'retry_at' => $terminal ? null : $request->available_at->toIso8601String()];
        });
    }

    public function findAuthorized(ExecutionContext $context, string $requestId, string $ability = 'genai:read'): McpRequest
    {
        return $this->authorizedRequest($context, $requestId, $ability, false);
    }

    /** Producer-side cancellation. The caller is responsible for authorizing its mailbox/domain job. */
    public function cancel(McpRequest|string $request): McpRequest
    {
        return $this->db->connection()->transaction(function () use ($request): McpRequest {
            $request = McpRequest::query()->lockForUpdate()->findOrFail(is_string($request) ? $request : $request->id);
            if (in_array($request->status, [McpRequestStatus::Completed, McpRequestStatus::Failed, McpRequestStatus::Expired], true)) {
                throw new McpQueueException('Terminal requests cannot be cancelled.', 409);
            }
            if ($request->status !== McpRequestStatus::Cancelled) {
                $request->forceFill(['status' => McpRequestStatus::Cancelled, 'lease_token_hash' => null, 'lease_expires_at' => null])->save();
                $this->db->connection()->afterCommit(fn () => event(new McpRequestCancelled($request->id)));
            }

            return $request;
        });
    }

    /** @return array<string, mixed> */
    public function publicStatus(McpRequest $request): array
    {
        return ['id' => $request->id, 'queue' => $request->queue, 'status' => $request->status->value,
            'attempt_count' => $request->attempt_count, 'max_attempts' => $request->max_attempts,
            'available_at' => $request->available_at->toIso8601String(), 'lease_expires_at' => $request->lease_expires_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(), 'failed_at' => $request->failed_at?->toIso8601String(),
            'receipt' => $request->status === McpRequestStatus::Completed ? $this->receipt($request) : null];
    }

    private function authorizedRequest(ExecutionContext $context, string $id, string $ability, bool $lock): McpRequest
    {
        $query = McpRequest::query()->with('mailbox')->whereIn('mailbox_id', $context->mailboxIds)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $request = $query->first();
        if ($request === null) {
            throw new McpQueueException('Request not found.', 404);
        }
        if (! $this->access->authorize($context, $request->mailbox, $ability, $request)) {
            throw new McpQueueException('Forbidden.', 403);
        }

        return $request;
    }

    private function expireRequests(ExecutionContext $context, ?string $queue): void
    {
        $requests = McpRequest::query()->with('mailbox')->whereIn('mailbox_id', $context->mailboxIds)
            ->when($queue !== null, fn ($query) => $query->where('queue', $queue))
            ->whereIn('status', [McpRequestStatus::Pending->value, McpRequestStatus::Leased->value])
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->lockForUpdate()->get();
        foreach ($requests as $request) {
            if (! $this->access->authorize($context, $request->mailbox, 'genai:read', $request)) {
                continue;
            }
            $request->forceFill([
                'status' => McpRequestStatus::Expired,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'lease_principal' => null,
            ])->save();
            $this->db->connection()->afterCommit(fn () => event(new McpRequestExpired($request->id)));
        }
    }

    private function failExhaustedLeases(ExecutionContext $context, ?string $queue): void
    {
        $requests = McpRequest::query()->with('mailbox')->whereIn('mailbox_id', $context->mailboxIds)
            ->when($queue !== null, fn ($query) => $query->where('queue', $queue))
            ->where('status', McpRequestStatus::Leased->value)->where('lease_expires_at', '<=', now())
            ->whereColumn('attempt_count', '>=', 'max_attempts')->lockForUpdate()->limit(25)->get();
        foreach ($requests as $request) {
            if (! $this->access->authorize($context, $request->mailbox, 'genai:work', $request)) {
                continue;
            }
            $error = ['code' => 'attempts_exhausted', 'message' => 'The final executor lease expired.'];
            $request->forceFill(['status' => McpRequestStatus::Failed, 'failed_at' => now(), 'error' => $error,
                'lease_token_hash' => null, 'lease_expires_at' => null, 'lease_principal' => null])->save();
            McpDelivery::query()->firstOrCreate(['request_id' => $request->id, 'type' => 'failed'], ['payload' => ['error' => $error], 'available_at' => now()]);
            $this->db->connection()->afterCommit(fn () => event(new McpRequestFailed($request->id, true)));
        }
    }

    private function leasedRequest(ExecutionContext $context, string $id, string $token): McpRequest
    {
        $request = $this->authorizedRequest($context, $id, 'genai:work', true);
        $this->assertLiveLease($request, $context, $token);

        return $request;
    }

    private function assertLiveLease(McpRequest $request, ExecutionContext $context, string $token): void
    {
        if ($request->expires_at?->isPast() || $request->status === McpRequestStatus::Expired) {
            throw new McpQueueException('Request has expired.', 410);
        }
        if ($request->status !== McpRequestStatus::Leased || $request->lease_expires_at?->isPast()
            || $request->lease_principal !== $context->principalKey
            || ! hash_equals((string) $request->lease_token_hash, hash('sha256', $token))) {
            throw new McpQueueException('Lease is stale, expired, or belongs to another executor.', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    private function assertPayloadBounds(array $payload, array $metadata): void
    {
        $bytes = strlen(json_encode(['payload' => $payload, 'metadata' => $metadata], JSON_THROW_ON_ERROR));
        if ($bytes > (int) config('genai.mcp.limits.max_enqueue_json_bytes', 2097152)) {
            throw new McpQueueException('Request manifest exceeds the configured limit.', 413);
        }
        $tools = $payload['tools'] ?? [];
        if (count($tools) > (int) config('genai.mcp.limits.max_tool_calls', 16)) {
            throw new McpQueueException('Too many tool definitions.', 413);
        }
        $names = array_column($tools, 'name');
        foreach ($names as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name)) {
                throw new McpQueueException('Tool names must be portable identifiers of at most 128 characters.', 422);
            }
        }
        if (count($names) !== count(array_unique($names))) {
            throw new McpQueueException('Tool names must be unique.', 422);
        }
        if (($payload['tool_choice']['type'] ?? null) === 'tool' && ! in_array($payload['tool_choice']['name'] ?? null, $names, true)) {
            throw new McpQueueException('The forced tool choice is not defined.', 422);
        }
        $inputCharacters = mb_strlen((string) ($payload['system'] ?? ''));
        foreach ($payload['messages'] ?? [] as $message) {
            if (! in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || ! isset($message['content']) || ! is_array($message['content']) || ! array_is_list($message['content'])) {
                throw new McpQueueException('Messages must have a portable user/assistant role and a content-block list.', 422);
            }
            foreach ($message['content'] as $block) {
                if (! is_array($block) || ! in_array($block['type'] ?? null, ['text', 'inline_attachment', 'stored_attachment', 'tool_call', 'tool_result'], true)) {
                    throw new McpQueueException('Message contains an unsupported content block.', 422);
                }
                if ($block['type'] === 'text') {
                    if (! is_string($block['text'] ?? null)) {
                        throw new McpQueueException('Text content blocks require text.', 422);
                    }
                    $inputCharacters += mb_strlen($block['text']);
                }
            }
        }
        if ($inputCharacters > (int) config('genai.mcp.limits.max_input_text_chars', 500000)) {
            throw new McpQueueException('Request text exceeds the configured limit.', 413);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function materializeAttachments(McpRequest $request, array $payload): array
    {
        $count = 0;
        foreach ($payload['messages'] as &$message) {
            foreach ($message['content'] as &$block) {
                if (! in_array($block['type'], ['inline_attachment', 'stored_attachment'], true)) {
                    continue;
                }
                if (++$count > (int) config('genai.mcp.limits.max_attachments', 20)) {
                    throw new McpQueueException('Too many attachments.', 413);
                }
                $id = (string) Str::uuid();
                if ($block['type'] === 'inline_attachment') {
                    $bytes = base64_decode((string) $block['base64'], true);
                    if ($bytes === false) {
                        throw new McpQueueException('Attachment base64 is invalid.', 422);
                    }
                    $this->assertAttachmentSize(strlen($bytes));
                    $disk = (string) config('genai.mcp.attachments.disk', 'local');
                    $path = 'genai-mcp/'.$request->id.'/'.$id;
                    Storage::disk($disk)->put($path, $bytes);
                    $attachment = new StoredAttachment((string) ($block['name'] ?? 'attachment-'.$id), (string) $block['mime_type'], strlen($bytes), hash('sha256', $bytes), $disk, $path, packageOwned: true);
                } else {
                    /** @var StoredAttachment $attachment */
                    $attachment = $block['attachment'];
                    $this->assertAttachmentSize($attachment->size);
                }
                McpAttachment::query()->create([
                    'id' => $id, 'request_id' => $request->id, 'disk' => $attachment->disk, 'path' => $attachment->path,
                    'host_reference' => $attachment->hostReference, 'name' => Str::limit(basename($attachment->name), 255, ''),
                    'mime_type' => $attachment->mimeType, 'size' => $attachment->size, 'sha256' => $attachment->sha256,
                    'package_owned' => $attachment->packageOwned,
                ]);
                $block = ['type' => 'attachment', 'attachment_id' => $id];
            }
        }
        unset($message, $block);

        return $payload;
    }

    private function assertAttachmentSize(int $size): void
    {
        if ($size < 0 || $size > (int) config('genai.mcp.limits.max_attachment_bytes', 104857600)) {
            throw new McpQueueException('Attachment exceeds the configured limit.', 413);
        }
    }

    private function assertQueueFilter(?string $queue): void
    {
        if ($queue !== null && ! preg_match('/^[A-Za-z0-9._-]{1,80}$/', $queue)) {
            throw new McpQueueException('Queue name is invalid.', 422);
        }
    }

    /** @return array<string, mixed> */
    private function envelope(McpRequest $request, string $plain): array
    {
        $expires = $request->lease_expires_at;

        $envelope = ['empty' => false, 'request' => [
            'id' => $request->id, 'queue' => $request->queue, 'attempt' => $request->attempt_count,
            'lease_token' => $plain, 'lease_expires_at' => $expires->toIso8601String(),
            'input' => ['system' => $request->payload['system'], 'messages' => $request->payload['messages']],
            'attachments' => $request->attachments->map(fn (McpAttachment $attachment): array => [
                'id' => $attachment->id, 'name' => $attachment->name, 'mime_type' => $attachment->mime_type,
                'size' => $attachment->size, 'sha256' => $attachment->sha256,
                'download_url' => URL::temporarySignedRoute('genai.mcp.attachments.show', $expires, [
                    'requestId' => $request->id, 'attachmentId' => $attachment->id, 'attempt' => $request->attempt_count,
                ]),
            ])->values()->all(),
            'tools' => $request->payload['tools'], 'tool_choice' => $request->payload['tool_choice'],
            'submission_schema' => $this->schemas->forPayload($request->payload),
        ]];
        if (strlen(json_encode($envelope, JSON_THROW_ON_ERROR)) > (int) config('genai.mcp.limits.max_claim_json_bytes', 3145728)) {
            throw new McpQueueException('Claim envelope exceeds the configured limit.', 413);
        }

        return $envelope;
    }

    /** @return array<string, mixed> */
    private function receipt(McpRequest $request): array
    {
        return ['request_id' => $request->id, 'status' => 'completed', 'receipt_id' => $request->completion_receipt_id, 'result' => $request->result];
    }

    /**
     * @param  array<string, mixed>  $executor
     * @return array<string, string>
     */
    private function sanitizeExecutor(array $executor): array
    {
        return array_filter(['client' => Str::limit((string) ($executor['client'] ?? ''), 100, ''), 'model' => Str::limit((string) ($executor['model'] ?? ''), 191, '')]);
    }

    private function backoffSeconds(int $attempt): int
    {
        $configured = array_values(array_filter(config('genai.mcp.retry_backoff_seconds', [60, 300, 1800]), 'is_int'));
        if ($configured === []) {
            return 60;
        }

        return max(1, min(86400, $configured[min(max(0, $attempt - 1), count($configured) - 1)]));
    }

    /** @param array<string, mixed> $data */
    private function canonicalJson(array $data): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
