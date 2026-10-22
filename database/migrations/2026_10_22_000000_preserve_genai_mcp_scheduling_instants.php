<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On servers with explicit_defaults_for_timestamp disabled, the first required
        // TIMESTAMP implicitly changes on every UPDATE. Scheduling instants must not.
        foreach ([
            'genai_mcp_requests' => 'available_at',
            'genai_mcp_deliveries' => 'available_at',
            'genai_mcp_claim_receipts' => 'expires_at',
        ] as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dateTime($column)->change();
            });
        }
    }

    public function down(): void
    {
        // Preserve the corrected types on rollback: reverting to TIMESTAMP could
        // silently overwrite persisted scheduling values on an unrelated update.
    }
};
