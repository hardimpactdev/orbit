<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Host reservations remain unique in vite_port_assignments; containers have independent network namespaces.
        Schema::table('instances', static function (Blueprint $table): void {
            $table->dropUnique('instances_node_id_vite_port_unique');
        });
    }

    public function down(): void
    {
        $duplicates = DB::table('instances')->whereNotNull('vite_port')->select(['node_id', 'vite_port'])
            ->groupBy(['node_id', 'vite_port'])->havingRaw('COUNT(*) > 1')->get();
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot restore instances_node_id_vite_port_unique: '.$duplicates->count()
                .' duplicate nonnull (node_id, vite_port) group(s). No schema, rows or ports were changed.');
        }
        Schema::table('instances', static function (Blueprint $table): void {
            $table->unique(['node_id', 'vite_port'], 'instances_node_id_vite_port_unique');
        });
    }
};
