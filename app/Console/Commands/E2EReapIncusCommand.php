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
    {--older-than=6h : Only include instances older than this (e.g. 30m, 2h, 1d)}
    {--json : Output as JSON}')]
#[Description('Reap stale Incus instances created by ephemeral E2E tests')]
class E2EReapIncusCommand extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        try {
            $olderThanMinutes = $this->parseOlderThan((string) $this->option('older-than'));
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

        foreach ($hosts as $host) {
            $result = $this->listInstances($host);

            if (! $result->successful()) {
                return $this->failCommand("Could not list instances on {$host->config->host}: {$result->errorOutput()}");
            }

            $instances = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($instances)) {
                return $this->failCommand("Unexpected incus list response from {$host->config->host}.");
            }

            $threshold = CarbonImmutable::now('UTC')->subMinutes($olderThanMinutes);

            foreach ($instances as $instance) {
                $name = $instance['name'] ?? '';

                if (! is_string($name) || $name === '') {
                    continue;
                }

                if (! str_starts_with($name, 'orbit-e2e-')) {
                    if (str_starts_with($name, 'orbit-template-') || str_starts_with($name, 'orbit-ready-')) {
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
                        return $this->failCommand("Could not delete instance {$name} on {$host->config->host}: {$deleteResult->errorOutput()}");
                    }

                    $resource['deleted'] = true;
                }

                $allResources[] = $resource;
            }
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

    private function deleteInstance($host, string $name): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\nincus delete --force {$name}"),
        );

        return Process::timeout($host->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg((string) $host->config->host),
                escapeshellarg($remoteCommand),
            ));
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
            $this->line('Deleted '.count($result['resources']).' stale Incus E2E instances.');
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
