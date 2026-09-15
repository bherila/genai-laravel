<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Explicit name: the generated one is 68 characters, above MySQL's 64-character identifier limit. */
    private const DELIVERY_PENDING_INDEX = 'genai_mcp_deliveries_pending_idx';

    public function up(): void
    {
        $this->createIfMissing('genai_mcp_mailboxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'name']);
        });

        $this->createIfMissing('genai_mcp_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('mailbox_id')->constrained('genai_mcp_mailboxes')->cascadeOnDelete();
            $table->string('name');
            $table->char('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('genai_mcp_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('mailbox_id')->constrained('genai_mcp_mailboxes')->cascadeOnDelete();
            $table->string('queue')->default('default');
            $table->string('status', 20)->default('pending');
            $table->integer('priority')->default(0);
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->char('enqueue_hash', 64)->nullable();
            $table->timestamp('available_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('leased_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->char('lease_token_hash', 64)->nullable();
            $table->string('lease_principal')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->char('completion_hash', 64)->nullable();
            $table->char('completion_lease_hash', 64)->nullable();
            $table->string('completion_principal')->nullable();
            $table->uuid('completion_receipt_id')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'idempotency_key']);
            $table->index(['mailbox_id', 'queue', 'status', 'available_at', 'priority'], 'genai_mcp_claim_idx');
        });

        $this->createIfMissing('genai_mcp_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('genai_mcp_requests')->cascadeOnDelete();
            $table->string('disk')->nullable();
            $table->text('path')->nullable();
            $table->text('host_reference')->nullable();
            $table->string('name');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->boolean('package_owned')->default(false);
            $table->timestamps();
        });

        $this->createIfMissing('genai_mcp_claim_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('genai_mcp_requests')->cascadeOnDelete();
            $table->foreignUuid('mailbox_id')->constrained('genai_mcp_mailboxes')->cascadeOnDelete();
            $table->string('principal_key');
            $table->string('idempotency_key');
            $table->string('queue_filter')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['principal_key', 'idempotency_key'], 'genai_mcp_claim_receipt_unique');
        });

        $this->createIfMissing('genai_mcp_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('genai_mcp_requests')->cascadeOnDelete();
            $table->string('type');
            $table->json('payload');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->uuid('lease_owner')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'type']);
            $table->index(['acknowledged_at', 'available_at', 'leased_until'], self::DELIVERY_PENDING_INDEX);
        });

        // A MySQL run of the first release of this migration created every table, then failed on the
        // delivery index (its generated name exceeded the 64-character identifier limit) before the
        // migration was recorded. Add the index to a table that exists without it.
        if (! Schema::hasIndex('genai_mcp_deliveries', self::DELIVERY_PENDING_INDEX)) {
            Schema::table('genai_mcp_deliveries', function (Blueprint $table): void {
                $table->index(['acknowledged_at', 'available_at', 'leased_until'], self::DELIVERY_PENDING_INDEX);
            });
        }
    }

    /**
     * Create a table unless it already exists. MySQL DDL is not transactional, so a failed run of this
     * migration can leave some tables behind without recording the migration; re-running must finish
     * the job rather than fail on the first table.
     *
     * @param  callable(Blueprint): void  $definition
     */
    private function createIfMissing(string $table, callable $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('genai_mcp_deliveries');
        Schema::dropIfExists('genai_mcp_claim_receipts');
        Schema::dropIfExists('genai_mcp_attachments');
        Schema::dropIfExists('genai_mcp_requests');
        Schema::dropIfExists('genai_mcp_tokens');
        Schema::dropIfExists('genai_mcp_mailboxes');
    }
};
