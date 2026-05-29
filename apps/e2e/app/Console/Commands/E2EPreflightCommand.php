<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHostPool;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('e2e:preflight {--json}')]
#[Description('Check that the configured Incus host is reachable')]
class E2EPreflightCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    /**
     * @var (Closure(): IncusHostPool)|null
     */
    private ?Closure $hostPoolFactory = null;

    public function setHostPoolFactory(Closure $factory): void
    {
        $this->hostPoolFactory = $factory;
    }

    public function handle(): int
    {
        $config = E2EConfig::fromEnvironment();

        $pool = $this->hostPoolFactory !== null
            ? ($this->hostPoolFactory)()
            : IncusHostPool::fromEnvironment($config);

        $host = $pool->first();

        if ($host === null) {
            return $this->failCommand('No Incus hosts configured');
        }

        $hosts = [];
        $allReachable = true;

        $hostName = $host->config->host;
        $reachable = true;
        $reason = null;
        $version = null;

        $versionResult = $host->run('incus version');
        if (! $versionResult->successful() || trim($versionResult->output()) === '') {
            $reachable = false;
            $reason = 'incus version check failed';
        } else {
            $version = $this->parseIncusVersion($versionResult->output());

            $lsResult = $host->run('incus ls --format json');
            if (! $lsResult->successful()) {
                $reachable = false;
                $reason = 'incus ls check failed';
            }
        }

        $hosts[] = [
            'host' => $hostName,
            'reachable' => $reachable,
            ...($reachable ? ['incus_version' => $version] : ['reason' => $reason]),
        ];

        if (! $reachable) {
            $allReachable = false;
        }

        $result = [
            'provider' => 'incus',
            'hosts' => $hosts,
        ];

        if ($allReachable) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            foreach ($hosts as $host) {
                $this->line("OK {$host['host']} (incus {$host['incus_version']})");
            }

            return self::SUCCESS;
        }

        return $this->failPreflight($result);
    }

    private function parseIncusVersion(string $output): string
    {
        if (preg_match('/Server version:\s*(\S+)/', $output, $matches)) {
            return $matches[1];
        }

        $lines = array_filter(array_map(trim(...), explode("\n", $output)));

        return $lines[0] ?? trim($output);
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'preflight_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array{provider: string, hosts: list<array{host: string, reachable: bool, incus_version?: string, reason?: string}>}  $result
     */
    private function failPreflight(array $result): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'preflight_failed',
                    'message' => 'One or more Incus hosts are unreachable',
                    'data' => $result,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        foreach ($result['hosts'] as $host) {
            if (! $host['reachable']) {
                $this->error("FAIL {$host['host']}: {$host['reason']}");
            }
        }

        return self::FAILURE;
    }
}
