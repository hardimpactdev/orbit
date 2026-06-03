<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use Illuminate\Support\Facades\DB;

final readonly class ManagedServiceToolProcessBackfill
{
    /**
     * @var array<string, array{
     *     process_tool: string,
     *     runtime: ProcessRuntime,
     *     command: string,
     *     default_image?: string,
     *     process_name?: string
     * }>
     */
    private const array SERVICES = [
        'mysql' => [
            'process_tool' => 'mysql',
            'runtime' => ProcessRuntime::Docker,
            'command' => 'mysqld',
            'default_image' => 'mysql:latest',
        ],
        'postgres' => [
            'process_tool' => 'postgres',
            'runtime' => ProcessRuntime::Docker,
            'command' => 'postgres',
            'default_image' => 'postgres:latest',
        ],
        'redis' => [
            'process_tool' => 'redis',
            'runtime' => ProcessRuntime::Docker,
            'command' => 'redis-server --bind 0.0.0.0 --protected-mode no',
            'default_image' => 'redis:latest',
        ],
        'opencode-server' => [
            'process_tool' => 'opencode',
            'runtime' => ProcessRuntime::Systemd,
            'command' => 'opencode serve -a',
            'process_name' => 'opencode-server',
        ],
        'polyscope-server' => [
            'process_tool' => 'polyscope',
            'runtime' => ProcessRuntime::Systemd,
            'command' => 'polyscope-server',
            'process_name' => 'polyscope-server',
        ],
    ];

    public function run(): void
    {
        NodeTool::query()
            ->whereIn('name', array_keys(self::SERVICES))
            ->with('node')
            ->orderBy('id')
            ->chunkById(100, function ($tools): void {
                foreach ($tools as $tool) {
                    $this->backfill($tool);
                }
            });
    }

    private function backfill(NodeTool $tool): void
    {
        if (! $tool->node instanceof Node) {
            return;
        }

        $definition = self::SERVICES[$tool->name] ?? null;

        if ($definition === null) {
            return;
        }

        $processName = $this->processName($tool, $definition);
        $ownerType = $tool->node->getMorphClass();

        DB::transaction(function () use ($tool, $definition, $processName, $ownerType): void {
            $exists = Process::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $tool->node_id)
                ->where('name', $processName)
                ->exists();

            if ($exists) {
                return;
            }

            $sortOrder = (int) (Process::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $tool->node_id)
                ->lockForUpdate()
                ->max('sort_order') ?? 0);

            Process::query()->create([
                'node_id' => $tool->node_id,
                'owner_type' => $ownerType,
                'owner_id' => $tool->node_id,
                'name' => $processName,
                'command' => $definition['command'],
                'restart_policy' => ProcessRestartPolicy::OnFailure,
                'crash_notification' => ProcessCrashNotification::None,
                'runtime' => $definition['runtime'],
                'tool' => $definition['process_tool'],
                'runtime_config' => $this->runtimeConfig($tool, $processName, $definition),
                'sort_order' => $sortOrder + 1,
            ]);
        });
    }

    /**
     * @param  array{process_name?: string}  $definition
     */
    private function processName(NodeTool $tool, array $definition): string
    {
        if (isset($definition['process_name'])) {
            return $definition['process_name'];
        }

        $version = $this->versionSuffix($tool);

        if ($version === null) {
            return $this->slug((string) $tool->name);
        }

        return $this->slug((string) $tool->name.$version);
    }

    private function versionSuffix(NodeTool $tool): ?string
    {
        $versionFamily = is_string($tool->version_family) ? trim($tool->version_family) : '';

        if ($versionFamily !== '') {
            return $versionFamily;
        }

        $instanceKey = is_string($tool->instance_key) ? trim($tool->instance_key) : '';
        $defaultKey = NodeTool::defaultInstanceKey((string) $tool->name);

        if ($instanceKey === '' || $instanceKey === $defaultKey || ! str_contains($instanceKey, ':')) {
            return null;
        }

        [, $version] = explode(':', $instanceKey, 2);
        $version = trim($version);

        return $version !== '' && $version !== 'default' ? $version : null;
    }

    /**
     * @param  array{
     *     runtime: ProcessRuntime,
     *     process_tool: string,
     *     default_image?: string
     * }  $definition
     * @return array<string, mixed>
     */
    private function runtimeConfig(NodeTool $tool, string $processName, array $definition): array
    {
        $config = is_array($tool->runtime_config) ? $tool->runtime_config : [];

        $config['source_node_tool_id'] = $tool->id;
        $config['tool_instance_key'] = $tool->instance_key;

        if ($definition['runtime'] === ProcessRuntime::Systemd) {
            $config['service'] = $processName;

            return $config;
        }

        $config['image'] = $this->stringValue($config['image'] ?? null)
            ?? $this->imageFor($tool, $definition['default_image'] ?? null);
        $config['network_aliases'] = array_values(array_unique([
            ...$this->stringList($config['network_aliases'] ?? []),
            $processName,
            (string) $tool->name,
        ]));

        return $config;
    }

    private function imageFor(NodeTool $tool, ?string $defaultImage): string
    {
        $version = $this->versionSuffix($tool);

        if ($version !== null) {
            return "{$tool->name}:{$version}";
        }

        return $defaultImage ?? "{$tool->name}:latest";
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'service';
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            fn (string $item): bool => $item !== '',
        ));
    }
}
