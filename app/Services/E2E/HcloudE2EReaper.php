<?php

declare(strict_types=1);

namespace App\Services\E2E;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class HcloudE2EReaper
{
    /**
     * @return array{
     *     provider: string,
     *     dry_run: bool,
     *     older_than_minutes: int,
     *     include_snapshots: bool,
     *     resources: list<array{type: string, id: string, name: string, created: string, deleted: bool}>
     * }
     */
    public function reap(int $olderThanMinutes = 60, bool $force = false, bool $includeSnapshots = false): array
    {
        $resources = $this->staleResources($olderThanMinutes, $includeSnapshots);

        if ($force) {
            $resources = array_map(function (array $resource): array {
                $this->delete($resource);

                $resource['deleted'] = true;

                return $resource;
            }, $resources);
        }

        return [
            'provider' => 'hcloud',
            'dry_run' => ! $force,
            'older_than_minutes' => $olderThanMinutes,
            'include_snapshots' => $includeSnapshots,
            'resources' => $resources,
        ];
    }

    /**
     * @return list<array{type: string, id: string, name: string, created: string, deleted: bool}>
     */
    private function staleResources(int $olderThanMinutes, bool $includeSnapshots): array
    {
        $resources = [
            ...$this->listResources('server', 'hcloud server list --selector orbit-e2e=true -o json'),
            ...$this->listResources('ssh-key', 'hcloud ssh-key list --selector orbit-e2e=true -o json'),
        ];

        if ($includeSnapshots) {
            $resources = [
                ...$resources,
                ...$this->listResources('image', 'hcloud image list --selector orbit-e2e=true --type snapshot -o json'),
            ];
        }

        $threshold = CarbonImmutable::now('UTC')->subMinutes($olderThanMinutes);

        return array_values(array_filter(
            $resources,
            fn (array $resource): bool => CarbonImmutable::parse($resource['created'])->lessThanOrEqualTo($threshold),
        ));
    }

    /**
     * @return list<array{type: string, id: string, name: string, created: string, deleted: bool}>
     */
    private function listResources(string $type, string $command): array
    {
        $result = Process::timeout(60)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException("Could not list hcloud {$type} resources: {$result->errorOutput()}");
        }

        $items = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($items)) {
            throw new RuntimeException("Unexpected hcloud {$type} list response.");
        }

        return array_values(array_filter(
            array_map(
                fn (array $item): array => [
                    'type' => $type,
                    'id' => (string) $item['id'],
                    'name' => (string) $item['name'],
                    'created' => (string) $item['created'],
                    'deleted' => false,
                ],
                $items,
            ),
            fn (array $resource): bool => ! str_starts_with((string) $resource['name'], 'orbit-ready-')
                && ! str_starts_with((string) $resource['name'], 'orbit-template-'),
        ));
    }

    /**
     * @param  array{type: string, id: string, name: string, created: string, deleted: bool}  $resource
     */
    private function delete(array $resource): void
    {
        $command = match ($resource['type']) {
            'server' => "hcloud server delete {$resource['id']}",
            'ssh-key' => "hcloud ssh-key delete {$resource['id']}",
            'image' => "hcloud image delete {$resource['id']}",
            default => throw new RuntimeException("Unknown hcloud resource type [{$resource['type']}]."),
        };

        $result = Process::timeout(120)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException("Could not delete hcloud {$resource['type']} {$resource['id']}: {$result->errorOutput()}");
        }
    }
}
