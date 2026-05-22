<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerHost;
use App\E2E\Support\E2EConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

#[Signature('e2e:reap-docker
    {--force : Delete stale Docker E2E containers and networks}
    {--older-than=30m : Only include resources older than this when creation time is available}
    {--json : Output as JSON}')]
#[Description('Reap stale Docker resources created by ephemeral E2E tests')]
class E2EReapDockerCommand extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        try {
            $olderThanMinutes = $this->parseOlderThan((string) $this->option('older-than'));
        } catch (InvalidArgumentException $exception) {
            return $this->failValidation($exception->getMessage());
        }

        $config = E2EConfig::fromEnvironment();
        $force = (bool) $this->option('force');

        $resources = [
            'containers' => [],
            'networks' => [],
            'volumes' => [],
        ];

        try {
            foreach ($this->dockerHosts($config) as $hostName) {
                $host = new DockerHost($config, $hostName);
                $containers = $this->listNames($host, sprintf(
                    'docker ps -a --format %s --filter %s',
                    escapeshellarg('{{.Names}}'),
                    escapeshellarg("name={$config->instancePrefix}-"),
                ), $config->instancePrefix);
                $networks = $this->listNames($host, sprintf(
                    'docker network ls --format %s --filter %s',
                    escapeshellarg('{{.Name}}'),
                    escapeshellarg("name={$config->instancePrefix}-"),
                ), $config->instancePrefix);
                $volumes = $this->listNames($host, sprintf(
                    'docker volume ls --format %s --filter %s',
                    escapeshellarg('{{.Name}}'),
                    escapeshellarg("name={$config->instancePrefix}-"),
                ), $config->instancePrefix);

                foreach ($containers as $container) {
                    $resources['containers'][] = [
                        'type' => 'container',
                        'host' => $hostName,
                        'name' => $container,
                        'deleted' => false,
                    ];
                }

                foreach ($networks as $network) {
                    $resources['networks'][] = [
                        'type' => 'network',
                        'host' => $hostName,
                        'name' => $network,
                        'deleted' => false,
                    ];
                }

                foreach ($volumes as $volume) {
                    $resources['volumes'][] = [
                        'type' => 'volume',
                        'host' => $hostName,
                        'name' => $volume,
                        'deleted' => false,
                    ];
                }
            }

            if ($force) {
                foreach ($resources['containers'] as &$container) {
                    $this->deleteResource(
                        new DockerHost($config, $container['host']),
                        sprintf('docker rm -f %s', escapeshellarg($container['name'])),
                        "Could not delete Docker container {$container['name']} on {$container['host']}",
                    );
                    $container['deleted'] = true;
                }

                foreach ($resources['networks'] as &$network) {
                    $this->deleteResource(
                        new DockerHost($config, $network['host']),
                        sprintf('docker network rm %s', escapeshellarg($network['name'])),
                        "Could not delete Docker network {$network['name']} on {$network['host']}",
                    );
                    $network['deleted'] = true;
                }

                foreach ($resources['volumes'] as &$volume) {
                    $this->deleteResource(
                        new DockerHost($config, $volume['host']),
                        sprintf('docker volume rm %s', escapeshellarg($volume['name'])),
                        "Could not delete Docker volume {$volume['name']} on {$volume['host']}",
                    );
                    $volume['deleted'] = true;
                }

                unset($container, $network, $volume);
            }
        } catch (RuntimeException $exception) {
            return $this->failDocker($exception->getMessage());
        }

        $result = [
            'provider' => 'docker',
            'dry_run' => ! $force,
            'older_than_minutes' => $olderThanMinutes,
            'filter' => 'prefix',
            'resources' => $resources,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderHuman($result);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function dockerHosts(E2EConfig $config): array
    {
        if ($config->dockerHostSlots !== []) {
            return array_keys($config->dockerHostSlots);
        }

        return $config->dockerHosts;
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

    /**
     * @return list<string>
     */
    private function listNames(DockerHost $host, string $command, string $instancePrefix): array
    {
        $result = $host->run($command);

        if (! $result->successful()) {
            throw new RuntimeException("Could not list Docker E2E resources on {$host->host}: ".trim($result->errorOutput().$result->output()));
        }

        return array_values(array_filter(
            array_map(trim(...), explode("\n", $result->output())),
            fn (string $name): bool => $name !== '' && str_starts_with($name, "{$instancePrefix}-"),
        ));
    }

    private function deleteResource(DockerHost $host, string $command, string $message): void
    {
        $result = $host->run($command);

        if (! $result->successful()) {
            throw new RuntimeException($message.': '.trim($result->errorOutput().$result->output()));
        }
    }

    /**
     * @param  array{
     *     provider: string,
     *     dry_run: bool,
     *     older_than_minutes: int,
     *     filter: string,
     *     resources: array{
     *         containers: list<array{type: string, host: string, name: string, deleted: bool}>,
     *         networks: list<array{type: string, host: string, name: string, deleted: bool}>,
     *         volumes: list<array{type: string, host: string, name: string, deleted: bool}>
     *     }
     * }  $result
     */
    private function renderHuman(array $result): void
    {
        if ($result['dry_run']) {
            $this->line('Dry run. Pass --force to delete Docker E2E resources.');
        }

        if ($result['resources']['containers'] === [] && $result['resources']['networks'] === [] && $result['resources']['volumes'] === []) {
            $this->line('No Docker E2E resources found.');

            return;
        }

        foreach ($result['resources']['containers'] as $container) {
            $status = $container['deleted'] ? 'deleted' : 'stale';
            $this->line("{$status}: container {$container['name']} on {$container['host']}");
        }

        foreach ($result['resources']['networks'] as $network) {
            $status = $network['deleted'] ? 'deleted' : 'stale';
            $this->line("{$status}: network {$network['name']} on {$network['host']}");
        }

        foreach ($result['resources']['volumes'] as $volume) {
            $status = $volume['deleted'] ? 'deleted' : 'stale';
            $this->line("{$status}: volume {$volume['name']} on {$volume['host']}");
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

    private function failDocker(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'docker_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
