<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fleet pass reads the converges of each Node and the Operations that hold a Node inside its write transaction. These indexes
     * reach those rows without reading the input JSON of the whole ledger: the converges of one Node by kind, Node and status, and the
     * timed-out runs whose native cancel may still be forwarded by error code. Each build reads the table once.
     */
    public function up(): void
    {
        Schema::table('operations', static function (Blueprint $table): void {
            if (! Schema::hasIndex('operations', 'operations_kind_node_id_status_created_at_index')) {
                $table->index(['kind', 'node_id', 'status', 'created_at']);
            }
            if (! Schema::hasIndex('operations', 'operations_error_code_index')) {
                $table->index('error_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operations', static function (Blueprint $table): void {
            $table->dropIndex(['kind', 'node_id', 'status', 'created_at']);
            $table->dropIndex(['error_code']);
        });
    }
};
