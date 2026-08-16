<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Enums\Apps\InstanceDriver;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppsFixer;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final readonly class DoctorAppRestorer
{
    public function __construct(
        private AppsFixer $appsFixer,
        private WorkspacePlacement $workspacePlacement = new WorkspacePlacement,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function apply(Node $node, string $key, array $detail): ?array
    {
        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;

        if ($appName === null) {
            return null;
        }

        if ($key === 'app.runtime_config_extra') {
            return $this->removeExtraRuntimeConfig($node, $appName);
        }

        $instanceName = is_string($detail['instance'] ?? null) ? $detail['instance'] : null;

        if ($instanceName !== null) {
            $app = App::query()
                ->with('instances')
                ->where('name', $appName)
                ->first();
            $instance = $app instanceof App
                ? $app->instances->firstWhere('name', $instanceName)
                : null;

            if (
                $app instanceof App
                && $instance instanceof Instance
                && $this->workspacePlacement->nodeForInstance($instance)?->id === $node->id
            ) {
                return $this->restoreInstance($app, $instance, $this->entry($key, $detail));
            }

            return null;
        }

        // Scope the restore to apps with a concrete Orbit instance on this node;
        // App owns no node_id column.
        $app = App::query()
            ->where('name', $appName)
            ->whereHas('instances', static fn (Builder $query): Builder => $query
                ->where('driver', InstanceDriver::Orbit->value)
                ->where('driver_config->data->node_id', $node->id))
            ->first();

        if (! $app instanceof App) {
            return null;
        }

        return $this->restoreApp($app, $this->entry($key, $detail));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreInstance(App $app, Instance $instance, DriftEntry $entry): ?array
    {
        try {
            return $this->appsFixer->fixInstance($app, $instance, $entry);
        } catch (Throwable $exception) {
            $node = $this->workspacePlacement->nodeForInstance($instance);

            return [
                'family' => $entry->family,
                'node' => $node?->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function removeExtraRuntimeConfig(Node $node, string $appName): array
    {
        try {
            return $this->appsFixer->removeRuntimeConfigExtra($node, $appName);
        } catch (Throwable $exception) {
            return [
                'family' => 'app',
                'node' => $node->name,
                'code' => 'app.runtime_config_extra',
                'key' => 'app.runtime_config_extra',
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to remove extra managed app runtime config for {$appName}.",
                'details' => [
                    'app' => $appName,
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreApp(App $app, DriftEntry $entry): ?array
    {
        try {
            return $this->appsFixer->fix($app, $entry);
        } catch (Throwable $exception) {
            return [
                'family' => $entry->family,
                'node' => $this->workspacePlacement->runtimeNode($app, null)?->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function entry(string $key, array $detail): DriftEntry
    {
        return new DriftEntry(
            family: 'app',
            key: $key,
            kind: DriftKind::Divergent,
            summary: '',
            detail: $detail,
        );
    }
}
