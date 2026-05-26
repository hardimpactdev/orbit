<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHostPool;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

#[Signature('e2e:reap-incus
    {--force : Delete stale instances instead of reporting a dry run}
    {--older-than=30m : Only include instances older than this (e.g. 30m, 2h, 1d)}
    {--artifacts-older-than=6h : Only include unused E2E images and storage volumes older than this}
    {--json : Output as JSON}')]
#[Description('Reap stale Incus instances created by ephemeral E2E tests')]
class E2EReapIncusCommand extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        try {
            $olderThanMinutes = $this->parseOlderThan((string) $this->option('older-than'));
            $artifactOlderThanMinutes = $this->parseOlderThan((string) $this->option('artifacts-older-than'));
        } catch (InvalidArgumentException $exception) {
            return $this->failValidation($exception->getMessage());
        }

        $force = (bool) $this->option('force');
        $config = E2EConfig::fromEnvironment();

        try {
            $pool = IncusHostPool::fromEnvironment($config);
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        $hosts = new \ReflectionClass($pool)->getProperty('hosts')->getValue($pool);

        if (! is_array($hosts) || $hosts === []) {
            return $this->failCommand('No Incus hosts configured.');
        }

        $allResources = [];
        $skippedNames = [];
        $instancePrefix = rtrim($config->instancePrefix, '-').'-';
        $instanceThreshold = CarbonImmutable::now('UTC')->subMinutes($olderThanMinutes);
        $artifactThreshold = CarbonImmutable::now('UTC')->subMinutes($artifactOlderThanMinutes);

        foreach ($hosts as $host) {
            $result = $this->listInstances($host);

            if (! $result->successful()) {
                return $this->failCommand("Could not list instances on {$host->config->host}: {$result->errorOutput()}");
            }

            $instances = $this->decodeJsonList($result->output(), "Unexpected incus list response from {$host->config->host}.");

            foreach ($instances as $instance) {
                $name = $instance['name'] ?? '';

                if (! is_string($name) || $name === '') {
                    continue;
                }

                if (! str_starts_with($name, $instancePrefix)) {
                    if (str_starts_with($name, 'orbit-template-') || str_starts_with($name, 'orbit-ready-')) {
                        $skippedNames[] = $name;
                    }

                    continue;
                }

                $createdAt = $instance['created_at'] ?? null;

                if (! is_string($createdAt) || $createdAt === '') {
                    continue;
                }

                $created = $this->parseTimestamp($createdAt);

                if ($created === null) {
                    continue;
                }

                if ($created->greaterThan($instanceThreshold)) {
                    continue;
                }

                $resource = [
                    'type' => 'instance',
                    'id' => $name,
                    'name' => $name,
                    'created' => $createdAt,
                    'deleted' => false,
                    'host' => $host->config->host,
                ];

                if ($force) {
                    $deleteResult = $this->deleteInstance($host, $name);

                    if (! $deleteResult->successful()) {
                        return $this->failCommand("Could not delete instance {$name} on {$host->config->host}: {$deleteResult->errorOutput()}");
                    }

                    $resource['deleted'] = true;
                }

                $allResources[] = $resource;
            }

            $imagesResult = $this->listImages($host);

            if (! $imagesResult->successful()) {
                return $this->failCommand("Could not list images on {$host->config->host}: {$imagesResult->errorOutput()}");
            }

            $images = $this->decodeJsonList($imagesResult->output(), "Unexpected incus image list response from {$host->config->host}.");

            foreach ($images as $image) {
                $resource = $this->imageResource($host, $image, $artifactThreshold, $instancePrefix, $skippedNames);

                if ($resource === null) {
                    continue;
                }

                if ($force) {
                    $deleteResult = $this->deleteImage($host, $resource['id']);

                    if (! $deleteResult->successful()) {
                        return $this->failCommand("Could not delete image {$resource['name']} on {$host->config->host}: {$deleteResult->errorOutput()}");
                    }

                    $resource['deleted'] = true;
                }

                $allResources[] = $resource;
            }

            $storagePoolNames = $this->storagePoolNames($host, $config);

            foreach ($storagePoolNames as $storagePoolName) {
                $volumesResult = $this->listStorageVolumes($host, $storagePoolName);

                if (! $volumesResult->successful()) {
                    return $this->failCommand("Could not list storage volumes on {$host->config->host}: {$volumesResult->errorOutput()}");
                }

                $volumes = $this->decodeJsonList($volumesResult->output(), "Unexpected incus storage volume list response from {$host->config->host}.");

                foreach ($volumes as $volume) {
                    $resource = $this->volumeResource($host, $storagePoolName, $volume, $artifactThreshold, $instancePrefix, $skippedNames);

                    if ($resource === null) {
                        continue;
                    }

                    if ($force) {
                        $deleteResult = $this->deleteStorageVolume($host, $resource['pool'], $resource['volume_type'], $resource['name']);

                        if (! $deleteResult->successful()) {
                            return $this->failCommand("Could not delete storage volume {$resource['name']} on {$host->config->host}: {$deleteResult->errorOutput()}");
                        }

                        $resource['deleted'] = true;
                    }

                    unset($resource['volume_type']);

                    $allResources[] = $resource;
                }
            }
        }

        $result = [
            'provider' => 'incus',
            'dry_run' => ! $force,
            'older_than_minutes' => $olderThanMinutes,
            'artifact_older_than_minutes' => $artifactOlderThanMinutes,
            'resources' => $allResources,
            'skipped' => array_values(array_unique($skippedNames)),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderHuman($result);

        return self::SUCCESS;
    }

    private function parseOlderThan(string $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (preg_match('/^(\d+)([mhd])$/', $value, $matches) === 1) {
            $amount = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'm' => $amount,
                'h' => $amount * 60,
                'd' => $amount * 24 * 60,
            };
        }

        throw new InvalidArgumentException("Invalid --older-than format: {$value}");
    }

    private function listInstances($host): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\nincus list --format json"),
        );

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    private function listImages($host): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\nincus image list --format json"),
        );

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    private function listStoragePools($host): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\nincus storage pool list --format json"),
        );

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    private function listStorageVolumes($host, string $poolName): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg('set -euo pipefail'."\n".'incus storage volume list '.escapeshellarg($poolName).' --format json'),
        );

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    private function deleteInstance($host, string $name): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg('set -euo pipefail'."\n".'incus delete --force '.escapeshellarg($name)),
        );

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    private function deleteImage($host, string $fingerprint): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg('set -euo pipefail'."\n".'incus image delete '.escapeshellarg($fingerprint)),
        );

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    private function deleteStorageVolume($host, string $poolName, string $type, string $name): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg('set -euo pipefail'."\n".'incus storage volume delete '.escapeshellarg($poolName).' '.escapeshellarg("{$type}/{$name}")),
        );

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeJsonList(string $output, string $errorMessage): array
    {
        $output = trim($output);

        if ($output === '') {
            return [];
        }

        try {
            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($errorMessage);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException($errorMessage);
        }

        $items = [];

        foreach ($decoded as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function storagePoolNames($host, E2EConfig $config): array
    {
        if ($config->incusStoragePool !== '') {
            return [$config->incusStoragePool];
        }

        $result = $this->listStoragePools($host);

        if (! $result->successful()) {
            throw new RuntimeException("Could not list storage pools on {$host->config->host}: {$result->errorOutput()}");
        }

        $pools = $this->decodeJsonList($result->output(), "Unexpected incus storage pool list response from {$host->config->host}.");
        $names = [];

        foreach ($pools as $pool) {
            $name = $pool['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $image
     * @param  list<string>  $skippedNames
     * @return array{type: string, id: string, name: string, created: string, last_used: string, deleted: bool, host: string}|null
     */
    private function imageResource($host, array $image, CarbonImmutable $threshold, string $instancePrefix, array &$skippedNames): ?array
    {
        $name = $this->firstAliasName($image);

        if ($name === null) {
            return null;
        }

        if ($this->isProtectedIncusArtifactName($name)) {
            $skippedNames[] = $name;

            return null;
        }

        if (! $this->isOrbitE2EArtifactName($name, $instancePrefix)) {
            return null;
        }

        $fingerprint = $image['fingerprint'] ?? null;

        if (! is_string($fingerprint) || $fingerprint === '') {
            return null;
        }

        $createdAt = $image['created_at'] ?? null;

        if (! is_string($createdAt) || $createdAt === '') {
            return null;
        }

        $lastUsedAt = $image['last_used_at'] ?? null;
        $ageSource = is_string($lastUsedAt) && $lastUsedAt !== '' && ! str_starts_with($lastUsedAt, '0001-01-01')
            ? $lastUsedAt
            : $createdAt;
        $usedAt = $this->parseTimestamp($ageSource);

        if ($usedAt === null || $usedAt->greaterThan($threshold)) {
            return null;
        }

        return [
            'type' => 'image',
            'id' => $fingerprint,
            'name' => $name,
            'created' => $createdAt,
            'last_used' => $ageSource,
            'deleted' => false,
            'host' => $host->config->host,
        ];
    }

    /**
     * @param  array<string, mixed>  $volume
     * @param  list<string>  $skippedNames
     * @return array{type: string, id: string, name: string, pool: string, volume_type: string, created: string, deleted: bool, host: string}|null
     */
    private function volumeResource($host, string $poolName, array $volume, CarbonImmutable $threshold, string $instancePrefix, array &$skippedNames): ?array
    {
        $name = $volume['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        if ($this->isProtectedIncusArtifactName($name)) {
            $skippedNames[] = $name;

            return null;
        }

        if (! $this->isOrbitE2EArtifactName($name, $instancePrefix)) {
            return null;
        }

        $usedBy = $volume['used_by'] ?? [];

        if (is_array($usedBy) && $usedBy !== []) {
            return null;
        }

        $createdAt = $volume['created_at'] ?? null;

        if (! is_string($createdAt) || $createdAt === '') {
            return null;
        }

        $created = $this->parseTimestamp($createdAt);

        if ($created === null || $created->greaterThan($threshold)) {
            return null;
        }

        $type = $volume['type'] ?? 'custom';
        $type = is_string($type) && $type !== '' ? $type : 'custom';

        return [
            'type' => 'volume',
            'id' => "{$poolName}/{$type}/{$name}",
            'name' => $name,
            'pool' => $poolName,
            'volume_type' => $type,
            'created' => $createdAt,
            'deleted' => false,
            'host' => $host->config->host,
        ];
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function firstAliasName(array $image): ?string
    {
        $aliases = $image['aliases'] ?? [];

        if (! is_array($aliases)) {
            return null;
        }

        foreach ($aliases as $alias) {
            if (! is_array($alias)) {
                continue;
            }

            $name = $alias['name'] ?? null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return null;
    }

    private function isOrbitE2EArtifactName(string $name, string $instancePrefix): bool
    {
        return str_starts_with($name, $instancePrefix)
            || str_starts_with($name, 'orbit-e2e-');
    }

    private function isProtectedIncusArtifactName(string $name): bool
    {
        return array_any(
            ['orbit-template-', 'orbit-ready-', 'orbit-base-', 'orbit-blank-', 'clean-'],
            fn ($prefix): bool => str_starts_with($name, (string) $prefix),
        );
    }

    private function parseTimestamp(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param  array{
     *     provider: string,
     *     dry_run: bool,
     *     older_than_minutes: int,
     *     artifact_older_than_minutes: int,
     *     resources: list<array<string, mixed>>,
     *     skipped: list<string>
     * }  $result
     */
    private function renderHuman(array $result): void
    {
        if ($result['dry_run']) {
            $this->line('Dry run. Pass --force to delete stale instances.');
        }

        if ($result['resources'] === []) {
            $this->line('No stale Incus E2E instances found.');

            return;
        }

        foreach ($result['resources'] as $resource) {
            $status = $resource['deleted'] ? 'deleted' : 'stale';

            $this->line("{$status}: {$resource['name']} on {$resource['host']} ({$resource['created']})");
        }

        if (! $result['dry_run']) {
            $onlyInstances = array_reduce(
                $result['resources'],
                fn (bool $carry, array $resource): bool => $carry && ($resource['type'] ?? null) === 'instance',
                true,
            );
            $label = $onlyInstances ? 'instances' : 'resources';

            $this->line('Deleted '.count($result['resources'])." stale Incus E2E {$label}.");
        }

        if ($result['skipped'] !== []) {
            $this->line('Skipped protected names: '.implode(', ', $result['skipped']));
        }
    }

    private function failValidation(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'incus_e2e_reap_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
