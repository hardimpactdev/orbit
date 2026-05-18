<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('e2e:prepare-docker-hosts
    {kind=operator-gateway-appdev-appprod : Topology kind to prepare (operator|operator-gateway|operator-gateway-appdev|operator-gateway-appdev-appprod)}
    {--force : Build the Docker runtime and topology images}
    {--runtime-only : Prepare only the Docker runtime image}
    {--topology-only : Prepare only the Docker prepared topology images}
    {--json : Output as JSON}')]
#[Description('Prepare Docker E2E images across all configured Docker hosts')]
class E2EPrepareDockerHostsCommand extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        $kindValue = (string) $this->argument('kind');
        $kind = E2ETopologyKind::tryFromInput($kindValue);

        if ($kind === null) {
            return $this->failCommand("Invalid topology kind [{$kindValue}]. Supported: operator, operator-gateway, operator-gateway-appdev, operator-gateway-appdev-appprod. Legacy control topology names are accepted as aliases.");
        }

        if ((bool) $this->option('runtime-only') && (bool) $this->option('topology-only')) {
            return $this->failCommand('Choose either --runtime-only or --topology-only, not both.');
        }

        $hosts = $this->hosts(E2EConfig::fromEnvironment());
        $steps = $this->steps($kind);

        if (! (bool) $this->option('force')) {
            return $this->dryRun($kind, $hosts, $steps);
        }

        $results = [];
        $failed = false;

        foreach ($hosts as $host) {
            foreach ($steps as $step) {
                $result = Process::timeout(3600)
                    ->env($this->environmentFor($host))
                    ->run($step['command']);

                $entry = [
                    'host' => $host,
                    'step' => $step['name'],
                    'successful' => $result->successful(),
                    'output' => trim($result->output().$result->errorOutput()),
                ];

                $results[] = $entry;

                if (! $entry['successful']) {
                    $failed = true;
                    $this->line("failed: {$host} {$step['name']} {$entry['output']}");
                } elseif (! (bool) $this->option('json')) {
                    $this->line("prepared: {$host} {$step['name']}");
                }
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                $failed ? 'error' : 'success' => [
                    'data' => [
                        'provider' => 'docker',
                        'dry_run' => false,
                        'kind' => $kind->value,
                        'hosts' => $hosts,
                        'results' => $results,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function hosts(E2EConfig $config): array
    {
        if ($config->dockerHostSlots !== []) {
            return array_keys($config->dockerHostSlots);
        }

        return $config->dockerHosts;
    }

    /**
     * @return list<array{name: string, command: string}>
     */
    private function steps(E2ETopologyKind $kind): array
    {
        $steps = [];

        if (! (bool) $this->option('topology-only')) {
            $steps[] = [
                'name' => 'runtime',
                'command' => 'composer e2e:prepare-docker-runtime -- --force',
            ];
        }

        if (! (bool) $this->option('runtime-only')) {
            $steps[] = [
                'name' => 'topology',
                'command' => sprintf('composer e2e:prepare-docker-topology -- --force %s', escapeshellarg($kind->value)),
            ];
        }

        return $steps;
    }

    /**
     * @param  list<string>  $hosts
     * @param  list<array{name: string, command: string}>  $steps
     */
    private function dryRun(E2ETopologyKind $kind, array $hosts, array $steps): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'provider' => 'docker',
                        'dry_run' => true,
                        'kind' => $kind->value,
                        'hosts' => $hosts,
                        'steps' => array_column($steps, 'name'),
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Dry run. Pass --force to prepare Docker images on all configured hosts.');

        foreach ($hosts as $host) {
            $this->line("planned: {$host}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(string $host): array
    {
        if ($host === 'local') {
            return [];
        }

        return ['DOCKER_HOST' => "ssh://{$host}"];
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'docker_host_prepare_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
