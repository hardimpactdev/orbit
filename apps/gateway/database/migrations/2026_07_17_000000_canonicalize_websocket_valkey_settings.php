<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('node_role')->where('role', 'websocket')->orderBy('id')->get() as $assignment) {
                $settings = json_decode((string) $assignment->settings, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($settings) || ! array_key_exists('redis_node_id', $settings)) {
                    continue;
                }

                $legacyNodeId = $settings['redis_node_id'];
                $canonicalNodeId = $settings['valkey_node_id'] ?? $legacyNodeId;

                if ($canonicalNodeId !== $legacyNodeId) {
                    throw new RuntimeException(
                        "Websocket role assignment [{$assignment->id}] has conflicting Redis and Valkey node settings.",
                    );
                }

                unset($settings['redis_node_id']);
                $settings['valkey_node_id'] = $canonicalNodeId;

                DB::table('node_role')
                    ->where('id', $assignment->id)
                    ->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void {}
};
