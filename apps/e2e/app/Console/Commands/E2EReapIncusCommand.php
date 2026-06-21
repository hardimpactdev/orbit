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
use RuntimeException;

#[Signature('e2e:reap-incus
    {--force : Delete stale instances instead of reporting a dry run}
    {--older-than=30m : Only include instances older than this (e.g. 30m, 2h, 1d)}
    {--scope=instances : Comma-separated resource scopes: instances, networks, templates, images, tmp, or all}
    {--json : Output as JSON}')]
#[Description('Reap stale Incus resources created by E2E tests')]
class E2EReapIncusCommand extends Command
{
    /** @var list<string> */
    private const array SupportedScopes = ['instances', 'networks', 'templates', 'images', 'tmp'];

    /** @var list<string> */
    private const array SharedTemplateNames = [
        'orbit-template-operator-base',
        'orbit-template-gateway-base',
        'orbit-template-app-dev-base',
        'orbit-template-app-prod-base',
        'orbit-template-agent-base',
        'orbit-template-ingress-base',
    ];

    /** @var list<string> */
    private const array LegacyTemplateNames = [
        'orbit-template-agent',
        'orbit-template-control',
        'orbit-template-dev',
        'orbit-template-gateway',
        'orbit-template-ingress',
        'orbit-template-ingress-control',
        'orbit-template-ingress-gateway',
        'orbit-template-ingress-prod',
        'orbit-template-prod',
        'orbit-template-projected-control',
    ];

    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        try {
            $olderThanMinutes = $this->parseOlderThan((string) $this->option('older-than'));
            $scopes = $this->parseScopes((string) $this->option('scope'));
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

        $hosts = $pool->hosts();

        if ($hosts === []) {
            return $this->failCommand('No Incus hosts configured.');
        }

        $allResources = [];
        $skippedNames = [];
        $instancePrefix = rtrim($config->instancePrefix, '-').'-';
        $threshold = CarbonImmutable::now('UTC')->subMinutes($olderThanMinutes);

        try {
            foreach ($hosts as $host) {
                $instances = null;

                if (in_array('instances', $scopes, true) || in_array('templates', $scopes, true)) {
                    $instances = $this->instances($host);

                    if ($instances === null) {
                        return $this->failCommand("Could not list instances on {$host->config->host}.");
                    }
                }

                if (in_array('instances', $scopes, true) && is_array($instances)) {
                    $allResources = [
                        ...$allResources,
                        ...$this->instanceResources(
                            host: $host,
                            instances: $instances,
                            instancePrefix: $instancePrefix,
                            threshold: $threshold,
                            force: $force,
                            skippedNames: $skippedNames,
                            recordProtectedTemplateSkips: ! in_array('templates', $scopes, true),
                        ),
                    ];
                }

                if (in_array('templates', $scopes, true) && is_array($instances)) {
                    $allResources = [
                        ...$allResources,
                        ...$this->templateResources(
                            host: $host,
                            instances: $instances,
                            threshold: $threshold,
                            force: $force,
                            skippedNames: $skippedNames,
                        ),
                    ];
                }

                if (in_array('networks', $scopes, true)) {
                    $networkResources = $this->networkResources($host, $config, $force);

                    if ($networkResources === null) {
                        return $this->failCommand("Could not list networks on {$host->config->host}.");
                    }

                    $allResources = [...$allResources, ...$networkResources];
                }

                if (in_array('images', $scopes, true)) {
                    $imageResources = $this->imageResources($host, $threshold, $force);

                    if ($imageResources === null) {
                        return $this->failCommand("Could not list images on {$host->config->host}.");
                    }

                    $allResources = [...$allResources, ...$imageResources];
                }

                if (in_array('tmp', $scopes, true)) {
                    $tmpResources = $this->tmpResources($host, $olderThanMinutes, $force);

                    if ($tmpResources === null) {
                        return $this->failCommand("Could not list /tmp artifacts on {$host->config->host}.");
                    }

                    $allResources = [...$allResources, ...$tmpResources];
                }
            }
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        $result = [
            'provider' => 'incus',
            'dry_run' => ! $force,
            'older_than_minutes' => $olderThanMinutes,
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
        if (preg_match('/^\d+$/', $value) === 1) {
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

    /**
     * @return list<string>
     */
    private function parseScopes(string $value): array
    {
        $scopes = array_values(array_filter(
            array_map(
                fn (string $scope): string => strtolower(trim($scope)),
                explode(',', $value),
            ),
            fn (string $scope): bool => $scope !== '',
        ));

        if ($scopes === []) {
            return ['instances'];
        }

        if (in_array('all', $scopes, true)) {
            return self::SupportedScopes;
        }

        $aliases = [
            'instance' => 'instances',
            'network' => 'networks',
            'template' => 'templates',
            'image' => 'images',
        ];

        $normalized = array_map(
            fn (string $scope): string => $aliases[$scope] ?? $scope,
            $scopes,
        );

        $unsupported = array_values(array_diff($normalized, self::SupportedScopes));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Unsupported --scope value(s): '.implode(', ', $unsupported).'. Supported scopes: '.implode(', ', self::SupportedScopes).', all.'
            );
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function instances($host): ?array
    {
        $result = $this->listInstances($host);

        if (! $result->successful()) {
            return null;
        }

        $instances = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);

        return is_array($instances) ? $instances : null;
    }

    /**
     * @param  list<array<string, mixed>>  $instances
     * @param  list<string>  $skippedNames
     * @return list<array<string, mixed>>
     */
    private function instanceResources(
        $host,
        array $instances,
        string $instancePrefix,
        CarbonImmutable $threshold,
        bool $force,
        array &$skippedNames,
        bool $recordProtectedTemplateSkips,
    ): array {
        $resources = [];

        foreach ($instances as $instance) {
            $name = $instance['name'] ?? '';

            if (! is_string($name) || $name === '') {
                continue;
            }

            if (! str_starts_with($name, $instancePrefix)) {
                if (
                    $recordProtectedTemplateSkips
                    && (str_starts_with($name, 'orbit-template-') || str_starts_with($name, 'orbit-ready-'))
                ) {
                    $skippedNames[] = $name;
                }

                continue;
            }

            $createdAt = $instance['created_at'] ?? null;

            if (! is_string($createdAt) || $createdAt === '') {
                continue;
            }

            try {
                $created = CarbonImmutable::parse($createdAt);
            } catch (\Exception) {
                continue;
            }

            if ($created->greaterThan($threshold)) {
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
                    throw new RuntimeException("Could not delete instance {$name} on {$host->config->host}: {$deleteResult->errorOutput()}");
                }

                $resource['deleted'] = true;
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    /**
     * @param  list<array<string, mixed>>  $instances
     * @param  list<string>  $skippedNames
     * @return list<array<string, mixed>>
     */
    private function templateResources(
        $host,
        array $instances,
        CarbonImmutable $threshold,
        bool $force,
        array &$skippedNames,
    ): array {
        $resources = [];

        foreach ($instances as $instance) {
            $name = $instance['name'] ?? '';

            if (! is_string($name) || ! str_starts_with($name, 'orbit-template-')) {
                continue;
            }

            if ($this->isProtectedTemplateName($name) || ! $this->isStaleTemplateName($name)) {
                $skippedNames[] = $name;

                continue;
            }

            $createdAt = $instance['created_at'] ?? null;

            if (! is_string($createdAt) || $createdAt === '') {
                continue;
            }

            try {
                $created = CarbonImmutable::parse($createdAt);
            } catch (\Exception) {
                continue;
            }

            if ($created->greaterThan($threshold)) {
                continue;
            }

            $snapshots = $this->snapshotNames($instance);
            $resource = [
                'type' => 'template',
                'id' => $name,
                'name' => $name,
                'created' => $createdAt,
                'snapshots' => $snapshots,
                'deleted' => false,
                'host' => $host->config->host,
            ];

            if ($force) {
                $deleteResult = $this->deleteInstance($host, $name);

                if (! $deleteResult->successful()) {
                    throw new RuntimeException("Could not delete template {$name} on {$host->config->host}: {$deleteResult->errorOutput()}");
                }

                $resource['deleted'] = true;
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    private function isProtectedTemplateName(string $name): bool
    {
        return in_array($name, self::SharedTemplateNames, true);
    }

    private function isStaleTemplateName(string $name): bool
    {
        if (in_array($name, self::LegacyTemplateNames, true)) {
            return true;
        }

        return preg_match('/^orbit-template-(prepared|projected|e2e-smoke)-/', $name) === 1;
    }

    /**
     * @param  array<string, mixed>  $instance
     * @return list<string>
     */
    private function snapshotNames(array $instance): array
    {
        $snapshots = $instance['snapshots'] ?? [];

        if (! is_array($snapshots)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $snapshot): ?string => is_array($snapshot) && is_string($snapshot['name'] ?? null)
                ? $snapshot['name']
                : null,
            $snapshots,
        )));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function networkResources($host, E2EConfig $config, bool $force): ?array
    {
        $result = $this->listNetworks($host);

        if (! $result->successful()) {
            return null;
        }

        $networks = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($networks)) {
            return null;
        }

        $resources = [];

        foreach ($networks as $network) {
            if (! is_array($network)) {
                continue;
            }

            $name = $network['name'] ?? '';
            $usedBy = $network['used_by'] ?? [];

            if (! is_string($name) || ! is_array($usedBy) || $usedBy !== []) {
                continue;
            }

            if (! $this->isWorkerNetworkName($name, $config)) {
                continue;
            }

            $resource = [
                'type' => 'network',
                'id' => $name,
                'name' => $name,
                'deleted' => false,
                'host' => $host->config->host,
                'reason' => 'unused_worker_network',
            ];

            if ($force) {
                $deleteResult = $this->deleteNetwork($host, $name);

                if (! $deleteResult->successful()) {
                    throw new RuntimeException("Could not delete network {$name} on {$host->config->host}: {$deleteResult->errorOutput()}");
                }

                $resource['deleted'] = true;
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    private function isWorkerNetworkName(string $name, E2EConfig $config): bool
    {
        for ($slot = 1; $slot <= 200; $slot++) {
            $suffix = "-n-{$slot}";
            $prefix = strtolower(preg_replace('/[^a-zA-Z0-9-]+/', '-', $config->instancePrefix) ?? '');
            $prefix = trim($prefix, '-');

            if ($prefix === '') {
                $prefix = 'orbit-e2e';
            }

            $expected = rtrim(substr($prefix, 0, 15 - strlen($suffix)), '-').$suffix;

            if ($name === $expected) {
                return true;
            }
        }

        return preg_match('/^orbit-e2e[a-z0-9-]*-n-\d+$/', $name) === 1;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function imageResources($host, CarbonImmutable $threshold, bool $force): ?array
    {
        $result = $this->listImages($host);

        if (! $result->successful()) {
            return null;
        }

        $images = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($images)) {
            return null;
        }

        $resources = [];

        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }

            $aliases = $this->imageAliasNames($image);
            $alias = collect($aliases)->first(fn (string $alias): bool => $this->isStaleImageAlias($alias));

            if (! is_string($alias) || ! $this->isStaleImageAlias($alias)) {
                continue;
            }

            if (! $this->isOldImage($image, $threshold)) {
                continue;
            }

            $resource = [
                'type' => 'image',
                'id' => $alias,
                'name' => $alias,
                'aliases' => $aliases,
                'created' => $this->stringValue($image['created_at'] ?? null),
                'expires_at' => $this->stringValue($image['expires_at'] ?? null),
                'last_used_at' => $this->stringValue($image['last_used_at'] ?? null),
                'size' => is_int($image['size'] ?? null) ? $image['size'] : null,
                'deleted' => false,
                'host' => $host->config->host,
            ];

            if ($force) {
                $deleteResult = $this->deleteImage($host, $alias);

                if (! $deleteResult->successful()) {
                    throw new RuntimeException("Could not delete image {$alias} on {$host->config->host}: {$deleteResult->errorOutput()}");
                }

                $resource['deleted'] = true;
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    /**
     * @param  array<string, mixed>  $image
     * @return list<string>
     */
    private function imageAliasNames(array $image): array
    {
        $aliases = $image['aliases'] ?? [];

        if (! is_array($aliases)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $alias): ?string => is_array($alias) && is_string($alias['name'] ?? null)
                ? $alias['name']
                : null,
            $aliases,
        )));
    }

    private function isStaleImageAlias(string $alias): bool
    {
        return str_starts_with($alias, 'orbit-blank-ubuntu-');
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function isOldImage(array $image, CarbonImmutable $threshold): bool
    {
        $expiresAt = $this->stringValue($image['expires_at'] ?? null);

        if ($expiresAt !== null) {
            try {
                if (CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                    return true;
                }
            } catch (\Exception) {
                //
            }
        }

        $lastUsedAt = $this->stringValue($image['last_used_at'] ?? null);

        if ($lastUsedAt !== null && ! str_starts_with($lastUsedAt, '0001-')) {
            try {
                return CarbonImmutable::parse($lastUsedAt)->lessThanOrEqualTo($threshold);
            } catch (\Exception) {
                return false;
            }
        }

        $createdAt = $this->stringValue($image['created_at'] ?? null);

        if ($createdAt === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($createdAt)->lessThanOrEqualTo($threshold);
        } catch (\Exception) {
            return false;
        }
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function tmpResources($host, int $olderThanMinutes, bool $force): ?array
    {
        $result = $this->listTmpArtifacts($host, $olderThanMinutes);

        if (! $result->successful()) {
            return null;
        }

        $resources = [];
        $lines = array_filter(explode("\n", trim($result->output())));

        foreach ($lines as $line) {
            [$path, $modified, $size] = array_pad(explode("\t", $line, 3), 3, '');

            if (! $this->isPrunableTmpPath($path)) {
                continue;
            }

            $resource = [
                'type' => 'tmp_path',
                'id' => $path,
                'name' => $path,
                'path' => $path,
                'modified' => $modified,
                'size' => is_numeric($size) ? (int) $size : null,
                'deleted' => false,
                'host' => $host->config->host,
            ];

            if ($force) {
                $deleteResult = $this->deleteTmpPath($host, $path);

                if (! $deleteResult->successful()) {
                    throw new RuntimeException("Could not delete tmp path {$path} on {$host->config->host}: {$deleteResult->errorOutput()}");
                }

                $resource['deleted'] = true;
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    private function isPrunableTmpPath(string $path): bool
    {
        if ($path === '/tmp/orbit-e2e-sources') {
            return false;
        }

        return str_starts_with($path, '/tmp/orbit-e2e-')
            || str_starts_with($path, '/tmp/orbit-current-transfer-')
            || str_starts_with($path, '/tmp/orbit-e2e-sources/');
    }

    private function listInstances($host): ProcessResult
    {
        return $this->runRemote($host, "set -euo pipefail\nincus list --format json");
    }

    private function listNetworks($host): ProcessResult
    {
        return $this->runRemote($host, "set -euo pipefail\nincus network list --format json");
    }

    private function listImages($host): ProcessResult
    {
        return $this->runRemote($host, "set -euo pipefail\nincus image list --format json");
    }

    private function listTmpArtifacts($host, int $olderThanMinutes): ProcessResult
    {
        $minutes = max(0, $olderThanMinutes);

        return $this->runRemote($host, sprintf(
            <<<'BASH'
set -euo pipefail
find /tmp -mindepth 1 -maxdepth 1 \( \( -name 'orbit-e2e-*' ! -name 'orbit-e2e-sources' \) -o -name 'orbit-current-transfer-*' \) -mmin +%1$d -printf '%%p\t%%TY-%%Tm-%%TdT%%TH:%%TM:%%TS%%Tz\t%%s\n' 2>/dev/null || true
if [ -d /tmp/orbit-e2e-sources ]; then
    find /tmp/orbit-e2e-sources -mindepth 1 -maxdepth 1 -mmin +%1$d -printf '%%p\t%%TY-%%Tm-%%TdT%%TH:%%TM:%%TS%%Tz\t%%s\n' 2>/dev/null || true
fi
BASH,
            $minutes,
        ));
    }

    private function deleteInstance($host, string $name): ProcessResult
    {
        return $this->runRemote($host, 'set -euo pipefail'."\n".'incus delete --force '.escapeshellarg($name));
    }

    private function deleteNetwork($host, string $name): ProcessResult
    {
        return $this->runRemote($host, 'set -euo pipefail'."\n".'incus network delete '.escapeshellarg($name));
    }

    private function deleteImage($host, string $alias): ProcessResult
    {
        return $this->runRemote($host, 'set -euo pipefail'."\n".'incus image delete '.escapeshellarg($alias));
    }

    private function deleteTmpPath($host, string $path): ProcessResult
    {
        return $this->runRemote($host, 'set -euo pipefail'."\n".'rm -rf -- '.escapeshellarg($path));
    }

    private function runRemote($host, string $script): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg($script),
        );

        if ($this->isLocalHost((string) $host->config->host)) {
            return Process::timeout($host->config->timeoutSeconds)
                ->run($remoteCommand);
        }

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['', 'localhost', '127.0.0.1', '::1'], true)
            || strtolower($host) === strtolower((string) gethostname());
    }

    /**
     * @param  array{
     *     provider: string,
     *     dry_run: bool,
     *     older_than_minutes: int,
     *     resources: list<array{type: string, id: string, name: string, created: string, deleted: bool, host: string}>,
     *     skipped: list<string>
     * }  $result
     */
    private function renderHuman(array $result): void
    {
        $resourceWord = $this->currentScopeResourceWord();

        if ($result['dry_run']) {
            $this->line("Dry run. Pass --force to delete stale {$resourceWord}.");
        }

        if ($result['resources'] === []) {
            $this->line("No stale Incus E2E {$resourceWord} found.");

            return;
        }

        foreach ($result['resources'] as $resource) {
            $status = $resource['deleted'] ? 'deleted' : 'stale';
            $timestamp = $resource['created'] ?? $resource['modified'] ?? $resource['reason'] ?? 'unknown';

            $this->line("{$status}: {$resource['name']} on {$resource['host']} ({$timestamp})");
        }

        if (! $result['dry_run']) {
            $this->line('Deleted '.count($result['resources'])." stale Incus E2E {$resourceWord}.");
        }

        if ($result['skipped'] !== []) {
            $this->line('Skipped protected names: '.implode(', ', $result['skipped']));
        }
    }

    private function currentScopeResourceWord(): string
    {
        try {
            $scopes = $this->parseScopes((string) $this->option('scope'));
        } catch (InvalidArgumentException) {
            return 'resources';
        }

        return $scopes === ['instances'] ? 'instances' : 'resources';
    }

    private function failValidation(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                    'meta' => [],
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
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
