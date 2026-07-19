<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('node_role')->where('role', 'analytics')->orderBy('id')->get() as $assignment) {
            $settingsJson = $assignment->settings ?? null;

            if (! is_string($settingsJson)) {
                continue;
            }

            $settings = json_decode($settingsJson, associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($settings)) {
                continue;
            }

            if (array_key_exists('postgres_process_id', $settings)) {
                continue;
            }

            $postgresNodeId = $settings['postgres_node_id'] ?? null;
            $postgresNode = is_int($postgresNodeId) ? Node::query()->find($postgresNodeId) : null;

            if (! $postgresNode instanceof Node) {
                continue;
            }

            $processes = Process::query()
                ->where('owner_type', $postgresNode->getMorphClass())
                ->where('owner_id', $postgresNode->getKey())
                ->where('runtime_config->service', 'postgres')
                ->orderBy('id')
                ->limit(2)
                ->get();

            if ($processes->count() !== 1) {
                continue;
            }

            $settings['postgres_process_id'] = $processes->sole()->id;
            DB::table('node_role')
                ->where('id', $assignment->id)
                ->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void {}
};
