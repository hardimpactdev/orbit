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
        DB::transaction(static function (): void {
            Schema::create('ai_router_process_sources', static function (Blueprint $table): void {
                $table->foreignId('process_id')->primary()->constrained('processes')->cascadeOnDelete();
                $table->string('source_revision', 64)->nullable();
                $table->boolean('activation_pending')->default(false);
            });

            DB::table('ai_router_process_sources')->insertUsing(
                ['process_id', 'source_revision', 'activation_pending'],
                DB::table('processes')->select('id', 'proxycli_source_revision', 'proxycli_source_activation_pending')
                    ->whereNotNull('proxycli_source_revision')->orWhereNotNull('proxycli_source_activation_pending'),
            );

            // Native drops preserve dependent rows and triggers; Laravel's SQLite rebuild can cascade deletes.
            DB::statement('ALTER TABLE processes DROP COLUMN proxycli_source_revision');
            DB::statement('ALTER TABLE processes DROP COLUMN proxycli_source_activation_pending');
        });
    }

    public function down(): void
    {
        DB::transaction(static function (): void {
            Schema::table('processes', static function (Blueprint $table): void {
                $table->string('proxycli_source_revision', 64)->nullable();
                $table->boolean('proxycli_source_activation_pending')->default(false);
            });

            DB::statement('UPDATE processes SET proxycli_source_revision = (SELECT source_revision FROM ai_router_process_sources WHERE process_id = processes.id), proxycli_source_activation_pending = (SELECT activation_pending FROM ai_router_process_sources WHERE process_id = processes.id) WHERE EXISTS (SELECT 1 FROM ai_router_process_sources WHERE process_id = processes.id)');
            Schema::drop('ai_router_process_sources');
        });
    }
};
