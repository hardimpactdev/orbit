<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('processes')->get(['id', 'runtime_config']) as $process) {
            $runtimeConfig = $this->runtimeConfig($process->runtime_config);
            $migrated = $this->migratedRuntimeConfig($runtimeConfig);

            if ($migrated === $runtimeConfig) {
                continue;
            }

            DB::table('processes')
                ->where('id', $process->id)
                ->update([
                    'runtime_config' => json_encode($migrated, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeConfig(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array<string, mixed>
     */
    private function migratedRuntimeConfig(array $runtimeConfig): array
    {
        $definition = $this->optionalString($runtimeConfig['definition'] ?? null);
        $service = $this->optionalString($runtimeConfig['service'] ?? null);

        if ($service === null && $definition !== null) {
            $runtimeConfig['service'] = $definition;
        }

        unset($runtimeConfig['definition']);

        if (! is_array($runtimeConfig['labels'] ?? null)) {
            return $runtimeConfig;
        }

        /** @var array<string, mixed> $labels */
        $labels = $runtimeConfig['labels'];
        $labelDefinition = $this->optionalString($labels['orbit.process.definition'] ?? null);
        $labelService = $this->optionalString($labels['orbit.process.service'] ?? null);

        if ($labelService === null && $labelDefinition !== null) {
            $labels['orbit.process.service'] = $labelDefinition;
        }

        unset($labels['orbit.process.definition']);
        $runtimeConfig['labels'] = $labels;

        return $runtimeConfig;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
};
