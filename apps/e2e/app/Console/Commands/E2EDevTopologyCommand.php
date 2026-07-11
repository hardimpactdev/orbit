<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerInstance;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyAcquisitionRetainedForDiagnosis;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyProvider;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyProvider;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature(
    'e2e:dev-topology
    {--dry-run : Render the acquisition plan without provisioning a topology}
    {--json : Output as JSON}
    {--kind=operator_gateway_app-dev : Prepared topology kind to acquire}
    {--provider=incus : Topology provider (incus|docker)}
    {--checkout-roles= : Comma-separated roles to overlay the current checkout onto (defaults to every role in the kind)}',
)]
#[Description('Acquire a retained prepared topology for manual + performance diagnosis')]
class E2EDevTopologyCommand extends Command
{
    /**
     * Stable WireGuard mesh addresses assigned per role by the Incus topology
     * provider. These mirror the prepared-topology contract so the printed
     * access handles point a human at the right node IP without re-deriving it.
     *
     * @var array<string, string>
     */
    private const array RoleWireGuardIps = [
        'gateway' => '10.6.0.2',
        'operator' => '10.6.0.3',
        'dev' => '10.6.0.4',
        'prod' => '10.6.0.5',
        'agent' => '10.6.0.6',
        'ingress' => '10.6.0.7',
    ];

    /**
     * Overlay roles that run a FrankenPHP app workload and are useful response
     * time targets for manual performance measurement.
     *
     * @var array<string, string>
     */
    private const array AppRoleLabels = [
        'dev' => 'app-dev',
        'prod' => 'app-prod',
    ];

    /**
     * @var (Closure(E2ETopologyKind, list<string>): array{
     *     host: string,
     *     run_id: string,
     *     source_path?: string,
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>,
     *     timings?: list<array{name: string, seconds: float}>
     * })|null
     */
    private ?Closure $prepareUsing = null;

    /**
     * Override the acquire-and-overlay step for unit tests so they never reach
     * beast (no provider clone, no SSH overlay).
     *
     * @param  Closure(E2ETopologyKind, list<string>): array{
     *     host: string,
     *     run_id: string,
     *     source_path?: string,
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>,
     *     timings?: list<array{name: string, seconds: float}>
     * }  $prepare
     */
    public function prepareUsing(Closure $prepare): void
    {
        $this->prepareUsing = $prepare;
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $kindInput = (string) $this->option('kind');
        $provider = (string) $this->option('provider');

        $kind = E2ETopologyKind::tryFromInput($kindInput);

        if ($kind === null) {
            return $this->renderError('validation_failed', "Unsupported E2E topology kind [{$kindInput}].", $json);
        }

        if (! in_array($provider, ['docker', 'incus'], true)) {
            return $this->renderError('validation_failed', "Unsupported E2E topology provider [{$provider}].", $json);
        }

        $displayRoles = $this->displayCheckoutRoles($kind);

        if ((bool) $this->option('dry-run')) {
            return $this->renderDryRun($kind, $provider, $displayRoles, $json);
        }

        return $this->acquireProvider($provider, $kind, $displayRoles, $json);
    }

    /**
     * @param  list<string>  $displayRoles
     */
    private function acquireProvider(string $provider, E2ETopologyKind $kind, array $displayRoles, bool $json): int
    {
        try {
            $manifest = $this->acquireRetainedTopology($provider, $kind, $displayRoles, streamTimings: ! $json);
        } catch (E2ETopologyAcquisitionRetainedForDiagnosis $exception) {
            $manifest = $this->writeRetainedFailureManifest($kind, $exception);

            return $this->renderRetainedAcquisitionFailure($exception->getMessage(), $manifest, $json);
        } catch (Throwable $exception) {
            return $this->renderError('acquisition_failed', $exception->getMessage(), $json);
        }

        return $this->renderAcquired($manifest, $json);
    }

    /**
     * @param  list<string>  $displayRoles
     * @return array{
     *     id: string,
     *     kind: string,
     *     provider: string,
     *     host: string,
     *     run_id: string,
     *     source_path: string,
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>,
     *     created_at: string
     * }
     */
    public function acquireRetainedIncusTopology(E2ETopologyKind $kind, array $displayRoles): array
    {
        return $this->acquireRetainedTopology('incus', $kind, $displayRoles);
    }

    /**
     * @param  list<string>  $displayRoles
     * @return array{
     *     id: string,
     *     kind: string,
     *     provider: string,
     *     host: string,
     *     run_id: string,
     *     source_path?: string,
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>,
     *     created_at: string
     * }
     */
    private function acquireRetainedTopology(
        string $provider,
        E2ETopologyKind $kind,
        array $displayRoles,
        bool $streamTimings = false,
    ): array {
        $config = E2EConfig::fromEnvironment();
        $overlayRoles = $this->overlayCheckoutRoles($kind, $displayRoles);

        $prepared = $this->prepareUsing !== null
            ? ($this->prepareUsing)($kind, $overlayRoles)
            : $this->acquireAndOverlay($config, $provider, $kind, $overlayRoles, $streamTimings);

        $manifest = $this->retainedManifest($config, $provider, $kind, $prepared);

        E2EDevTopologyManifestStore::fromEnvironment(repo_path())->write($manifest);

        return $manifest;
    }

    /**
     * @param  array{
     *     host: string,
     *     run_id: string,
     *     source_path?: string,
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>,
     *     timings?: list<array{name: string, seconds: float}>
     * }  $prepared
     * @return array<string, mixed>
     */
    private function retainedManifest(
        E2EConfig $config,
        string $provider,
        E2ETopologyKind $kind,
        array $prepared,
    ): array {
        $manifest = [
            'id' => $prepared['run_id'],
            'kind' => $kind->value,
            'provider' => $provider,
            'host' => $prepared['host'],
            'run_id' => $prepared['run_id'],
            'ssh_key_path' => $prepared['ssh_key_path'],
            'gateway_ip' => $prepared['gateway_ip'],
            'instances' => $prepared['instances'],
            'checkouts' => $prepared['checkouts'],
            'created_at' => now()->toIso8601String(),
            'release_command' => $this->releaseCommandFor($provider, $prepared['run_id']),
        ];

        if (isset($prepared['timings']) && is_array($prepared['timings'])) {
            $manifest['timings'] = $this->normalizeTimings($prepared['timings']);
        }

        if ($provider === 'incus') {
            $sourcePath = $prepared['source_path'] ?? null;

            if (! is_string($sourcePath) || trim($sourcePath) === '') {
                throw new \RuntimeException('A retained Incus topology requires its exact host source path.');
            }

            $manifest['source_path'] = $sourcePath;
        }

        if ($provider === 'docker') {
            $manifest += $this->dockerManifestDetails($config, $prepared['run_id'], $prepared['instances']);
        }

        return $manifest;
    }

    /**
     * @param  list<array{name: string, seconds: float|int}>  $timings
     * @return list<array{name: string, seconds: float}>
     */
    private function normalizeTimings(array $timings): array
    {
        return array_map(
            fn (array $timing): array => [
                'name' => $timing['name'],
                'seconds' => round((float) $timing['seconds'], 3),
            ],
            $timings,
        );
    }

    /**
     * Reuse the topology factory's machinery, but inject a distinct, identifiable
     * dev run id so the cloned instances never collide with ephemeral test clones
     * and stay easy to reap on release. Then overlay the current checkout and
     * retain (do not reap) the topology.
     *
     * @param  list<string>  $overlayRoles
     * @return array{
     *     host: string,
     *     run_id: string,
     *     source_path: string|null,
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>,
     *     timings: list<array{name: string, seconds: float}>
     * }
     */
    private function acquireAndOverlay(
        E2EConfig $config,
        string $providerName,
        E2ETopologyKind $kind,
        array $overlayRoles,
        bool $streamTimings,
    ): array {
        $runId = 'dev-'.bin2hex(random_bytes(3));
        $timer = new E2EPhaseTimer(
            stream: $streamTimings && ! $this->laravel->runningUnitTests(),
            writer: function (string $line): void {
                $this->output->writeln($line);
            },
        );
        $provider = $this->provider($config, $providerName);

        try {
            $availability = $timer->measure('prepared-availability', fn () => $provider->availability($kind));

            if (! $availability->available) {
                throw new \RuntimeException('Prepared topology not available: '.$availability->message);
            }

            $lease = $provider->acquire(
                $kind,
                $runId,
                $timer,
                new E2ETopologyAcquisitionOptions(
                    startGatewayApi: true,
                    sourceMountedCheckout: true,
                    retainOnFailure: $providerName === 'docker',
                ),
            );
        } finally {
            $timer->flush('acquire');
        }

        $host = $this->hostForLease($providerName, $config, $lease);
        $harness = new E2ETopologyHarness($lease, cleanupOnRelease: false)->setTimer($timer);

        try {
            $timer->measure('checkout.overlay', fn () => $harness->withCurrentCheckout($overlayRoles));
        } catch (Throwable $exception) {
            try {
                $lease->cleanup();
            } catch (Throwable $cleanupException) {
                $exception = new \RuntimeException(
                    $exception->getMessage().' Topology cleanup also failed: '.$cleanupException->getMessage(),
                    previous: $exception,
                );
            }

            throw $exception;
        }

        return [
            'host' => $host,
            'run_id' => $runId,
            'source_path' =>
                $providerName === 'incus' && $lease->operator() instanceof IncusInstance
                    ? $lease->operator()->hostSourcePath()
                    : null,
            'ssh_key_path' => $lease->sshKeyPair()->privateKeyPath,
            'gateway_ip' => $lease->gatewayApiIp(),
            'instances' => $this->instanceNamesByRole($lease, $this->manifestRolesForKind($kind)),
            'checkouts' => $harness->checkouts(),
            'timings' => $this->normalizeTimings($timer->events()),
        ];
    }

    private function provider(E2EConfig $config, string $provider): E2ETopologyProvider
    {
        return match ($provider) {
            'docker' => new DockerTopologyProvider($config),
            'incus' => new IncusTopologyProvider($config),
            default => throw new \RuntimeException("Unsupported E2E topology provider [{$provider}]."),
        };
    }

    private function hostForLease(string $provider, E2EConfig $config, E2ETopologyLease $lease): string
    {
        if ($provider !== 'docker') {
            $availability = IncusHostPool::fromEnvironment($config)->availabilityFor(
                $lease->kind(),
                checkCapacity: false,
            );

            return $availability['host']?->config->host ?? $config->host;
        }

        $operator = $lease->operator();

        return $operator instanceof DockerInstance
            ? $operator->hostName()
            : 'local';
    }

    /**
     * @param  list<string>  $names
     */
    /**
     * @param  list<string>  $overlayRoles
     * @return array<string, string>
     */
    private function instanceNamesByRole(E2ETopologyLease $lease, array $overlayRoles): array
    {
        $roles = array_values(array_unique(['operator', ...$overlayRoles]));
        $instances = [];

        foreach ($roles as $role) {
            $instance = $lease->instance($role);

            if ($instance instanceof E2EInstance) {
                $instances[$role] = $instance->name();
            }
        }

        return $instances;
    }

    /**
     * @param  array<string, string>  $instances
     * @return array{network: string, managed_containers: list<string>, volumes: list<string>}
     */
    private function dockerManifestDetails(E2EConfig $config, string $runId, array $instances): array
    {
        return [
            'network' => "{$config->instancePrefix}-{$runId}",
            'managed_containers' => $this->dockerManagedContainers($instances),
            'volumes' => $this->dockerManagedVolumes($instances),
        ];
    }

    /**
     * @param  array<string, string>  $instances
     * @return list<string>
     */
    private function dockerManagedContainers(array $instances): array
    {
        $containers = [];

        foreach ($instances as $role => $nodeContainer) {
            if ($role === 'gateway') {
                $containers[] = "{$nodeContainer}-orbit-gateway";
            }

            $containers[] = "{$nodeContainer}-orbit-caddy";
            $containers[] = $nodeContainer;
        }

        return array_values(array_unique($containers));
    }

    /**
     * @param  array<string, string>  $instances
     * @return list<string>
     */
    private function dockerManagedVolumes(array $instances): array
    {
        $volumes = [];

        foreach ($instances as $nodeContainer) {
            array_push(
                $volumes,
                "{$nodeContainer}-home-orbit",
                "{$nodeContainer}-etc-caddy",
                "{$nodeContainer}-etc-orbit",
                "{$nodeContainer}-opt-orbit",
            );
        }

        return array_values(array_unique($volumes));
    }

    private function releaseCommandFor(string $provider, string $id): string
    {
        return $provider === 'incus'
            ? "composer e2e:incus -- --stop --id={$id}"
            : "composer e2e:dev-topology:release -- {$id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function writeRetainedFailureManifest(
        E2ETopologyKind $kind,
        E2ETopologyAcquisitionRetainedForDiagnosis $exception,
    ): array {
        $manifest = [
            'id' => $exception->runId,
            'kind' => $kind->value,
            'provider' => $exception->provider,
            'host' => $exception->host,
            'run_id' => $exception->runId,
            'ssh_key_path' => '/dev/null',
            'gateway_ip' => '10.6.0.2',
            'instances' => $exception->instances,
            'checkouts' => [],
            'created_at' => now()->toIso8601String(),
            'release_command' => $this->releaseCommandFor($exception->provider, $exception->runId),
            'network' => $exception->network,
            'managed_containers' => $exception->managedContainers,
            'volumes' => $exception->volumes,
            'retained_after_failure' => true,
            'failure_message' => $exception->getMessage(),
        ];

        if ($exception->resourceLease !== null) {
            $manifest['resource_lease'] = $exception->resourceLease;
        }

        E2EDevTopologyManifestStore::fromEnvironment(repo_path())->write($manifest);

        return $manifest;
    }

    /**
     * @param  array{
     *     id: string,
     *     kind: string,
     *     provider: string,
     *     host: string,
     *     run_id: string,
     *     source_path?: string,
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>,
     *     created_at: string
     * }  $manifest
     */
    private function renderAcquired(array $manifest, bool $json): int
    {
        $handles = $this->buildHandles($manifest);
        $releaseCommand = is_string($manifest['release_command'] ?? null)
            ? $manifest['release_command']
            : $this->releaseCommandFor($manifest['provider'], $manifest['id']);

        if ($json) {
            $this->line(json_encode([
                'success' => [
                    'dev_topology' => [
                        ...$manifest,
                        'release_command' => $releaseCommand,
                        'handles' => $handles,
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Retained topology [{$manifest['id']}] acquired.");
        $this->line("Kind: {$manifest['kind']}");
        $this->line("Provider: {$manifest['provider']} (host {$manifest['host']})");
        $this->line("Gateway API: http://{$manifest['gateway_ip']}");
        $this->renderTimings($manifest);

        if ($this->sourceMountedCheckout($manifest)) {
            $runtimeCheckout = $this->sourceMountedRuntimeCheckout($manifest);

            $this->line('Source-mounted checkout: '.E2ECurrentCheckout::sourceMountedGuestPath());

            if ($runtimeCheckout !== null) {
                $this->line("Runtime checkout: {$runtimeCheckout}");
                $this->line("Launcher: {$runtimeCheckout}/apps/cli/orbit");
            } else {
                $this->line('Launcher: '.E2ECurrentCheckout::sourceMountedGuestPath().'/apps/cli/orbit');
            }
        }
        $this->line('');

        foreach ($handles as $handle) {
            $this->line("[{$handle['role']}] {$handle['instance']}");
            $this->line("  ssh: {$handle['ssh_example']}");

            if (isset($handle['endpoint'])) {
                $this->line("  endpoint: {$handle['endpoint']}");
            }

            if (isset($handle['perf_example'])) {
                $this->line("  perf: {$handle['perf_example']}");
            }

            if (isset($handle['note'])) {
                $this->line("  note: {$handle['note']}");
            }
        }

        $this->line('');
        $this->line("Release: {$releaseCommand}");
        $this->line(
            'Retained topologies are manual diagnosis tools; they are not standing infrastructure and must be released.',
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function renderTimings(array $manifest): void
    {
        $timings = $manifest['timings'] ?? null;

        if (! is_array($timings) || $timings === []) {
            return;
        }

        $this->line('Timings:');

        foreach ($timings as $timing) {
            if (! is_array($timing) || ! isset($timing['name'], $timing['seconds'])) {
                continue;
            }

            $this->line(sprintf('  %s: %.3fs', $timing['name'], (float) $timing['seconds']));
        }
    }

    /**
     * @param  array{checkouts: array<string, string>}  $manifest
     */
    private function sourceMountedCheckout(array $manifest): bool
    {
        return collect($manifest['checkouts'])
            ->contains(
                fn (string $checkout): bool => (
                    $checkout === E2ECurrentCheckout::sourceMountedGuestPath()
                    || $checkout === E2ECurrentCheckout::sourceMountedRuntimePath($this->userForCheckoutPath($checkout))
                ),
            );
    }

    /**
     * @param  array{checkouts: array<string, string>}  $manifest
     */
    private function sourceMountedRuntimeCheckout(array $manifest): ?string
    {
        foreach ($manifest['checkouts'] as $checkout) {
            if (! is_string($checkout)) {
                continue;
            }

            if ($checkout === E2ECurrentCheckout::sourceMountedRuntimePath($this->userForCheckoutPath($checkout))) {
                return $checkout;
            }
        }

        return null;
    }

    private function userForCheckoutPath(string $checkout): string
    {
        $parts = explode('/', trim($checkout, '/'));

        return $parts[1] ?? 'orbit';
    }

    /**
     * @param  array{
     *     host: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>
     * }  $manifest
     * @return list<array{role: string, instance: string, ssh_example: string, endpoint?: string, curl_example?: string}>
     */
    private function buildHandles(array $manifest): array
    {
        $host = $manifest['host'];
        $provider = $manifest['provider'] ?? 'incus';
        $handles = [];

        foreach ($manifest['instances'] as $role => $instance) {
            $user = $this->userForRole($role);
            $checkout = $manifest['checkouts'][$role] ?? "/home/{$user}/orbit";

            $handle = [
                'role' => $role,
                'instance' => $instance,
                'ssh_example' => $provider === 'docker'
                    ? $this->dockerExecExample($host, $instance, $user, "cd {$checkout} && orbit node:list --json")
                    : sprintf(
                        'ssh %s incus exec %s -- sudo -u %s bash -lc %s',
                        $host,
                        $instance,
                        $user,
                        escapeshellarg("cd {$checkout} && orbit node:list --json"),
                    ),
            ];

            if ($role === 'gateway') {
                // Immediate control-plane latency probe: the gateway serves the CA
                // bootstrap over plain http with no auth, so it is a clean
                // round-trip signal for how fast the gateway responds. Works with
                // no app deployed.
                $command = "curl -sS -o /dev/null -w 'gateway /api/ca/root: %{time_total}s\\n' http://{$manifest['gateway_ip']}/api/ca/root";
                $handle['perf_example'] = $provider === 'docker'
                    ? $this->dockerExecExample($host, $instance, 'root', $command)
                    : sprintf(
                        'ssh %s incus exec %s -- bash -lc %s',
                        $host,
                        $instance,
                        escapeshellarg($command),
                    );
            }

            if (isset(self::AppRoleLabels[$role])) {
                $wireGuardIp = self::RoleWireGuardIps[$role];
                // The FrankenPHP app runtime is installed on this node, but a fresh
                // topology serves no apps yet — nothing answers on the node until
                // one is deployed, and app traffic is served by the gateway router
                // (Caddy) at the app domain, not the node port directly.
                $handle['endpoint'] = "{$wireGuardIp} ({$role} node WireGuard address; FrankenPHP app runtime — no app served until you deploy one)";
                $handle['note'] = 'Deploy an app from the operator (orbit app:new <name>), then `orbit app:show <name> --json` shows its domain; curl that domain through the gateway router with -w "%{time_total}s" to measure response time.';
            }

            $handles[] = $handle;
        }

        return $handles;
    }

    private function dockerExecExample(string $host, string $instance, string $user, string $command): string
    {
        return sprintf(
            '%s exec --user %s %s sh -lc %s',
            $this->dockerCliExample($host),
            escapeshellarg($user),
            escapeshellarg($instance),
            escapeshellarg($command),
        );
    }

    private function dockerCliExample(string $host): string
    {
        return $host === 'local'
            ? 'docker'
            : 'DOCKER_HOST='.escapeshellarg("ssh://{$host}").' docker';
    }

    private function userForRole(string $role): string
    {
        return $role === 'operator'
            ? E2EConfig::fromEnvironment()->operatorUser
            : 'orbit';
    }

    /**
     * @param  list<string>  $displayRoles
     */
    private function renderDryRun(E2ETopologyKind $kind, string $provider, array $displayRoles, bool $json): int
    {
        $shellCommand = $this->shellCommand($kind, $provider, $displayRoles);
        $releaseCommand = $this->releaseCommandFor($provider, 'dry-run');
        $payload = [
            'id' => 'dry-run',
            'dry_run' => true,
            'provider' => $provider,
            'host' => 'dry-run',
            'kind' => $kind->value,
            'checkout_roles' => $displayRoles,
            'shell_command' => $shellCommand,
            'release_command' => $releaseCommand,
        ];

        if ($json) {
            $this->line(json_encode(['success' => [
                'dev_topology' => $payload,
            ]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Retained topology dry run');
        $this->line("Provider: {$provider}");
        $this->line("Kind: {$kind->value}");
        $this->line('Checkout roles: '.implode(', ', $displayRoles));
        $this->line("Shell: {$shellCommand}");
        $this->line("Release: {$releaseCommand}");
        $this->line(
            'Source-checkout E2E remains the normal feature loop; retained topologies are manual diagnosis only.',
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $displayRoles
     */
    private function shellCommand(E2ETopologyKind $kind, string $provider, array $displayRoles): string
    {
        $command = $provider === 'incus'
            ? "composer e2e:incus -- --start --topology={$kind->value}"
            : "composer e2e:dev-topology -- --kind={$kind->value} --provider={$provider}";

        if ($displayRoles !== $this->displayCheckoutRolesForKind($kind)) {
            $command .= ' --checkout-roles='.implode(',', $displayRoles);
        }

        return $command;
    }

    /**
     * Resolve the requested checkout roles in the display vocabulary used by the
     * dry-run plan (`app-dev`, `app-prod`). Falls back to every role in the kind.
     *
     * @return list<string>
     */
    private function displayCheckoutRoles(E2ETopologyKind $kind): array
    {
        $value = $this->option('checkout-roles');

        return $this->displayCheckoutRolesForInput($kind, is_string($value) ? $value : null);
    }

    /**
     * @return list<string>
     */
    public function displayCheckoutRolesForInput(E2ETopologyKind $kind, ?string $checkoutRoles): array
    {
        $requested = $this->requestedCheckoutRoles($checkoutRoles);

        return $requested === []
            ? $this->displayCheckoutRolesForKind($kind)
            : $requested;
    }

    /**
     * @return list<string>
     */
    private function requestedCheckoutRoles(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $roles = [];

        foreach (explode(',', $value) as $role) {
            $role = trim($role);

            if ($role !== '') {
                $roles[] = $role;
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return list<string>
     */
    private function displayCheckoutRolesForKind(E2ETopologyKind $kind): array
    {
        $roles = ['operator'];

        if (str_contains($kind->value, 'gateway')) {
            $roles[] = 'gateway';
        }

        if (str_contains($kind->value, 'app-dev')) {
            $roles[] = 'app-dev';
        }

        if (str_contains($kind->value, 'app-prod')) {
            $roles[] = 'app-prod';
        }

        if (str_contains($kind->value, 'ingress')) {
            $roles[] = 'ingress';
        }

        if (str_contains($kind->value, 'agent')) {
            $roles[] = 'agent';
        }

        if (str_contains($kind->value, 'websocket')) {
            $roles[] = 'websocket';
        }

        return $roles;
    }

    /**
     * Map the display roles onto the canonical overlay vocabulary understood by
     * the checkout helper and the topology lease (`dev`, `prod`).
     *
     * @param  list<string>  $displayRoles
     * @return list<string>
     */
    private function overlayCheckoutRoles(E2ETopologyKind $kind, array $displayRoles): array
    {
        $map = [
            'app-dev' => 'dev',
            'app-prod' => 'prod',
            'dev' => 'dev',
            'prod' => 'prod',
        ];

        $roles = [];

        foreach ($displayRoles as $role) {
            $roles[] = $map[$role] ?? $role;
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return list<string>
     */
    private function manifestRolesForKind(E2ETopologyKind $kind): array
    {
        return $this->overlayCheckoutRoles($kind, $this->displayCheckoutRolesForKind($kind));
    }

    private function renderError(string $code, string $message, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->components->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function renderRetainedAcquisitionFailure(string $message, array $manifest, bool $json): int
    {
        $releaseCommand = is_string($manifest['release_command'] ?? null)
            ? $manifest['release_command']
            : $this->releaseCommandFor((string) $manifest['provider'], (string) $manifest['id']);

        if ($json) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'acquisition_failed_retained',
                    'message' => $message,
                    'retained_topology' => $manifest
                        + [
                            'release_command' => $releaseCommand,
                        ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->components->error($message);
        $this->line("Retained failed topology [{$manifest['id']}] for diagnosis.");
        $this->line("Provider: {$manifest['provider']} (host {$manifest['host']})");
        $this->line("Release: {$releaseCommand}");

        return self::FAILURE;
    }
}
