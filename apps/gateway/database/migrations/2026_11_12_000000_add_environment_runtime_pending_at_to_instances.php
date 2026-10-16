<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->timestamp('environment_runtime_pending_at')->nullable();
        });

        DB::table('instances')->select('id')->eachById(function (object $instance): void {
            if (Cache::has('instance-environment-runtime-pending:'.$instance->id)) {
                DB::table('instances')->where('id', $instance->id)->update([
                    'environment_runtime_pending_at' => now(),
                ]);
            }
        });
        // Keep legacy cache entries until normal cache cleanup: the database transaction
        // may still roll back, and the cache cannot participate in that transaction.
    }

    public function down(): void
    {
        // Preserve pending work for the cache-backed implementation on rollback.
        DB::table('instances')->whereNotNull('environment_runtime_pending_at')->select('id')->eachById(function (object $instance): void {
            Cache::forever('instance-environment-runtime-pending:'.$instance->id, true);
        });

        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn('environment_runtime_pending_at');
        });
    }
};
