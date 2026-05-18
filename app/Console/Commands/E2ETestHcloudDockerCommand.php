<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2ETopologyKind;
use App\Services\E2E\HcloudDockerE2ERunner;
use App\Services\E2E\HcloudDockerE2ERunOptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('e2e:test-hcloud-docker
    {--force : Create a temporary Hetzner Docker host and run Docker feature E2E}
    {--keep : Keep the temporary Hetzner server and SSH key}
    {--source-image=ubuntu-24.04 : hcloud base image}
    {--server-type=cx23 : hcloud server type}
    {--location=nbg1 : hcloud location}
    {--resource-slots= : Resource slots as location/server-type/image:slots}
    {--prefix=orbit-e2e : Resource name prefix}
    {--kind=operator-gateway-appdev-appprod : Docker topology kind to prepare}
    {--processes=2 : Pest worker count for composer test:e2e:docker}
    {--timeout=3600 : Timeout in seconds for long hcloud Docker E2E steps}
    {--json : Output as JSON}')]
#[Description('Run Docker feature E2E on a temporary Hetzner Cloud Docker host')]
class E2ETestHcloudDockerCommand extends Command
{
    protected $hidden = true;

    public function handle(HcloudDockerE2ERunner $runner): int
    {
        $kind = E2ETopologyKind::tryFromInput((string) $this->option('kind'));

        if ($kind === null) {
            return $this->failCommand('Invalid --kind. Supported: operator, operator-gateway, operator-gateway-appdev, operator-gateway-appdev-appprod. Legacy control topology names are accepted as aliases.');
        }

        $processes = (int) $this->option('processes');

        if ($processes < 1) {
            return $this->failCommand('--processes must be at least 1.');
        }

        $timeout = (int) $this->option('timeout');

        if ($timeout < 60) {
            return $this->failCommand('--timeout must be at least 60 seconds.');
        }

        $options = new HcloudDockerE2ERunOptions(
            force: (bool) $this->option('force'),
            keep: (bool) $this->option('keep'),
            sourceImage: (string) $this->option('source-image'),
            serverType: (string) $this->option('server-type'),
            location: (string) $this->option('location'),
            resourceSlots: $this->parseResourceSlots(),
            slotWaitSeconds: $this->envInt('ORBIT_E2E_SLOT_WAIT_SECONDS', 900),
            slotStaleSeconds: $this->envInt('ORBIT_E2E_SLOT_STALE_SECONDS', 7200),
            prefix: (string) $this->option('prefix'),
            kind: $kind->value,
            processes: $processes,
            timeoutSeconds: $timeout,
        );

        try {
            $result = $runner->run($options);
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderHuman($result, $options);

        return self::SUCCESS;
    }

    /**
     * @param  array{provider: string, dry_run: bool, server: string, docker_host: string, prepared: bool, tested: bool}  $result
     */
    private function renderHuman(array $result, HcloudDockerE2ERunOptions $options): void
    {
        if ($result['dry_run']) {
            $this->line('Dry run. Pass --force to create a temporary Hetzner Docker host.');
            $this->line("planned: create Hetzner server in {$options->location}");
            $this->line("planned: prepare Docker topology {$options->kind}");
            $this->line('planned: run composer test:e2e:docker against Docker on that server');

            return;
        }

        $this->line("prepared: {$result['docker_host']}");
        $this->line('passed: composer test:e2e:docker');
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'hcloud_docker_e2e_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @return array<string, int>
     */
    private function parseResourceSlots(): array
    {
        $value = (string) $this->option('resource-slots');

        if ($value === '') {
            $environment = getenv('ORBIT_E2E_HCLOUD_RESOURCE_SLOTS');
            $value = is_string($environment) ? $environment : '';
        }

        $entries = array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            fn (string $entry): bool => $entry !== '',
        ));

        $slots = [];

        foreach ($entries as $entry) {
            [$resource, $slotCount] = array_pad(array_map(trim(...), explode(':', $entry, 2)), 2, '');

            if ($resource === '' || $slotCount === '') {
                throw new RuntimeException("Invalid --resource-slots entry [{$entry}]. Expected location/server-type/image:slots.");
            }

            $count = (int) $slotCount;

            if ((string) $count !== $slotCount || $count < 1) {
                throw new RuntimeException("Invalid --resource-slots count [{$slotCount}] for resource [{$resource}].");
            }

            $slots[strtolower($resource)] = $count;
        }

        return $slots;
    }

    private function envInt(string $key, int $default): int
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return (int) $value;
    }
}
