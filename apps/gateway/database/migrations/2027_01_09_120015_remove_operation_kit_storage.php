<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('operation_steps');
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropForeign(['parent_operation_id']);
            $table->dropUnique(['parent_operation_id', 'child_key']);
            $table->dropColumn(['parent_operation_id', 'child_key', 'child_admission', 'child_fingerprint', 'run_constraints', 'wait_delay_seconds', 'next_read_at']);
        });
    }

    public function down(): void
    {
        // Native runs cannot be converted back into Gateway execution receipts.
    }
};
