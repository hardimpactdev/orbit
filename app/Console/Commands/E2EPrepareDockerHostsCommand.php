<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\Services\E2E\DockerImageDistributor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

#[Signature('e2e:prepare-docker-hosts
    {kind=operator-gateway-appdev-appprod : Topology kind to prepare (operator|operator-gateway|operator-gateway-appdev|operator-gateway-appdev-appprod)}
    {--force : Build the Docker runtime and topology images}
    {--runtime-only : Prepare only the Docker runtime image}
    {--topology-only : Prepare only the Docker prepared topology images}
    {--topology-mode=dns-alias : Topology mode to bake (legacy-retarget|dns-alias)}
    {--json : Output as JSON}')]
#[Description('Prepare Docker E2E images across all configured Docker hosts')]
class E2EPrepareDockerHostsCommand extends Command
{
    private const string RuntimeImage = 'orbit-e2e-topology-runtime:current';

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

        $mode = (string) $this->option('topology-mode');

        if (! $this->isSupportedTopologyMode($mode)) {
            return $this->failCommand("Invalid topology mode [{$mode}]. Supported: legacy-retarget, dns-alias.");
        }

        $config = E2EConfig::fromEnvironment();

        if (count($config->dockerImageBuildHosts) > 1) {
            return $this->failCommand('Configure exactly one Docker image build host. Use ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast and keep sidecars in ORBIT_E2E_DOCKER_HOST_SLOTS only.');
        }

        $hosts = $this->hosts($config);
        $buildHosts = $this->buildHosts($config, $hosts);
        $buildHost = $buildHosts[0] ?? 'local';
        $runtimeStep = $this->runtimeStep();
        $topologySteps = $this->topologySteps($kind, $mode);
        $steps = array_values(array_filter([
            $runtimeStep,
            ...$topologySteps,
        ]));

        if (! (bool) $this->option('force')) {
            return $this->dryRun($kind, $hosts, $buildHosts, $steps);
        }

        $results = [];
        $failed = false;
        $pendingImagesByHost = [];

        if ($runtimeStep !== null) {
            if (! $this->runBuildStep($buildHost, $runtimeStep, $results)) {
                $failed = true;
            } else {
                $this->queuePendingImages($pendingImagesByHost, $buildHost, $runtimeStep['images']);
            }
        }

        if (! $failed && $topologySteps !== []) {
            foreach ($topologySteps as $step) {
                if (! $this->runBuildStep($buildHost, $step, $results)) {
                    $failed = true;

                    break;
                }

                $this->queuePendingImages($pendingImagesByHost, $buildHost, $step['images']);
            }
        }

        if (! $failed) {
            foreach ($pendingImagesByHost as $buildHost => $images) {
                if (! $this->distributeImages($buildHost, $config, $images, $hosts, 'distribution', $results)) {
                    $failed = true;

                    break;
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
                        'topology_mode' => $mode,
                        'hosts' => $hosts,
                        'build_hosts' => $buildHosts,
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
     * @param  list<string>  $targetHosts
     * @return list<string>
     */
    private function buildHosts(E2EConfig $config, array $targetHosts): array
    {
        if ($config->dockerImageBuildHosts !== []) {
            return $config->dockerImageBuildHosts;
        }

        if ($targetHosts === ['local']) {
            return ['local'];
        }

        if ($config->incusImageBuildHost !== '') {
            return [$config->incusImageBuildHost];
        }

        return $targetHosts !== [] ? [$targetHosts[0]] : ['local'];
    }

    /**
     * @return array{name: string, command: string, images: list<array{role: string, image: string}>}|null
     */
    private function runtimeStep(): ?array
    {
        if ((bool) $this->option('topology-only')) {
            return null;
        }

        return [
            'name' => 'runtime',
            'command' => 'composer e2e:prepare-docker-runtime -- --force',
            'images' => [
                ['role' => 'runtime', 'image' => self::RuntimeImage],
            ],
        ];
    }

    /**
     * @return list<array{name: string, command: string, images: list<array{role: string, image: string}>}>
     */
    private function topologySteps(E2ETopologyKind $kind, string $mode): array
    {
        if ((bool) $this->option('runtime-only')) {
            return [];
        }

        $roles = array_values(array_filter(
            DockerTopologyBuilder::rolesFor($kind),
            fn (string $role): bool => DockerTopologyBuilder::ownsImage($kind, $role),
        ));

        $images = array_map(
            fn (string $role): array => [
                'role' => $role,
                'image' => DockerTopologyBuilder::imageNameFor($kind, $role, $mode),
            ],
            $roles,
        );

        return [[
            'name' => 'topology',
            'command' => sprintf('composer e2e:prepare-docker-topology -- --force --topology-mode=%s %s', escapeshellarg($mode), escapeshellarg($kind->value)),
            'images' => $images,
        ]];
    }

    /**
     * @param  list<string>  $hosts
     * @param  list<string>  $buildHosts
     * @param  list<array{name: string, command: string, images: list<array{role: string, image: string}>}>  $steps
     */
    private function dryRun(E2ETopologyKind $kind, array $hosts, array $buildHosts, array $steps): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'provider' => 'docker',
                        'dry_run' => true,
                        'kind' => $kind->value,
                        'topology_mode' => (string) $this->option('topology-mode'),
                        'hosts' => $hosts,
                        'build_hosts' => $buildHosts,
                        'steps' => array_column($steps, 'name'),
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Dry run. Pass --force to build Docker images on the build host and distribute them to configured hosts.');

        foreach ($buildHosts as $buildHost) {
            $this->line("builder: {$buildHost}");
        }

        foreach ($hosts as $host) {
            $this->line("planned: {$host}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{name: string, command: string, images: list<array{role: string, image: string}>}  $step
     * @param  list<array<string, mixed>>  $results
     */
    private function runBuildStep(string $buildHost, array $step, array &$results): bool
    {
        $result = Process::timeout(3600)
            ->env($this->environmentFor($buildHost))
            ->run($step['command']);

        $entry = [
            'host' => $buildHost,
            'step' => $step['name'],
            'successful' => $result->successful(),
            'output' => trim($result->output().$result->errorOutput()),
        ];

        $results[] = $entry;

        if (! $entry['successful']) {
            $this->line("failed: {$buildHost} {$step['name']} {$entry['output']}");

            return false;
        }

        if (! (bool) $this->option('json')) {
            $this->line("built: {$buildHost} {$step['name']}");
        }

        return true;
    }

    /**
     * @param  array<string, list<array{role: string, image: string}>>  $pendingImagesByHost
     * @param  list<array{role: string, image: string}>  $images
     */
    private function queuePendingImages(array &$pendingImagesByHost, string $buildHost, array $images): void
    {
        $pendingImagesByHost[$buildHost] = [
            ...($pendingImagesByHost[$buildHost] ?? []),
            ...$images,
        ];
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

    private function imageDistributorFor(string $buildHost, E2EConfig $config): DockerImageDistributor
    {
        return app()->makeWith(DockerImageDistributor::class, [
            'sourceHost' => $buildHost,
            'timeoutSeconds' => $config->timeoutSeconds,
        ]);
    }

    /**
     * @param  list<array{role: string, image: string}>  $images
     * @param  list<string>  $hosts
     * @param  list<array<string, mixed>>  $results
     */
    private function distributeImages(string $buildHost, E2EConfig $config, array $images, array $hosts, string $step, array &$results): bool
    {
        try {
            $imports = $this->imageDistributorFor($buildHost, $config)->distribute($images, $hosts);
        } catch (Throwable $exception) {
            $this->line("failed: {$buildHost} {$step} {$exception->getMessage()}");

            return false;
        }

        foreach ($imports as $import) {
            $results[] = [
                ...$import,
                'step' => $step,
                'successful' => true,
                'output' => '',
            ];

            if (! (bool) $this->option('json')) {
                $this->line("imported: {$import['host']} {$import['image']}");
            }
        }

        return true;
    }

    private function isSupportedTopologyMode(string $mode): bool
    {
        return in_array($mode, ['legacy-retarget', 'dns-alias'], true);
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
