<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Persist php-cli variant on role-owned NodeTool rows that predate the feature.
 *
 * Pre-feature rows often have config=null. Doctor/install already derive intent
 * from app-dev/app-prod roles, but restore and inventory stay clearer when the
 * stored config matches role ownership (app-prod=standard, app-dev=coverage).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->backfillRole('app-prod', 'standard');
        $this->backfillRole('app-dev', 'coverage');
    }

    public function down(): void
    {
        // Non-destructive: leave persisted variants in place.
    }

    private function backfillRole(string $role, string $variant): void
    {
        $rows = DB::table('node_tools')
            ->where('name', 'php-cli')
            ->whereExists(static function (Builder $query) use ($role): void {
                $query
                    ->selectRaw('1')
                    ->from('node_role')
                    ->whereColumn('node_role.node_id', 'node_tools.node_id')
                    ->where('node_role.role', $role)
                    ->whereIn('node_role.status', ['pending', 'active']);
            })
            ->get(['id', 'config']);

        foreach ($rows as $row) {
            $config = $this->decodeConfig($row->config ?? null);

            // Skip only when the stored variant already matches role ownership.
            // Overwrite stale coverage on app-prod and stale standard on app-dev.
            if (($config['variant'] ?? null) === $variant) {
                continue;
            }

            $config['variant'] = $variant;

            DB::table('node_tools')
                ->where('id', $row->id)
                ->update([
                    'config' => json_encode($config, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            /** @var array<string, mixed> $normalized */
            $normalized = [];

            foreach ($raw as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }

            return $normalized;
        }

        if (! is_string($raw) || trim($raw) === '' || $raw === 'null') {
            return [];
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
};
