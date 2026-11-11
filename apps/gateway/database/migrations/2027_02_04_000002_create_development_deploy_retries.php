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
        if (! Schema::hasTable('development_deploy_retries')) {
            Schema::create('development_deploy_retries', function (Blueprint $table): void {
                $table->foreignId('instance_id')->constrained('instances')->cascadeOnDelete();
                $table->string('commit', 64);
                $table->json('state');
                $table->primary(['instance_id', 'commit']);
            });
        }
        DB::table('instances')->whereNotNull('auto_deploy_state')->select('id')->orderBy('id')->chunkById(100, function ($instances): void {
            foreach ($instances as $instance) {
                DB::transaction(function () use ($instance): void {
                    $stored = DB::table('instances')->where('id', $instance->id)->lockForUpdate()->value('auto_deploy_state');
                    $state = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
                    // The cursor marks a completed import; repeating up must never replay stale JSON after a reset.
                    if (isset($state['development_retry_cursor']) || ! isset($state['development_retries'])) {
                        return;
                    }
                    $ledger = $state['development_retries'];
                    foreach ($ledger['commits'] as $entry) {
                        DB::table('development_deploy_retries')->insertOrIgnore(['instance_id' => $instance->id, 'commit' => $entry['commit'],
                            'state' => json_encode(array_diff_key($entry, ['commit' => true]), JSON_THROW_ON_ERROR)]);
                    }
                    $state['development_retry_cursor'] = array_diff_key($ledger, ['commits' => true]);
                    unset($state['development_retries']);
                    DB::table('instances')->where('id', $instance->id)->update(['auto_deploy_state' => json_encode($state, JSON_THROW_ON_ERROR)]);
                });
            }
        });
    }

    public function down(): void
    {
        // Expand-only: preserve stopped SHAs, reset boundaries and the cursor on rollback.
    }
};
