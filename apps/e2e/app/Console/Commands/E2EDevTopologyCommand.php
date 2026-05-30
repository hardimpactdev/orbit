<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyProviderPool;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusHostPool;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('e2e:dev-topology
    {--dry-run : Render the acquisition plan without provisioning a topology}
    {--json : Output as JSON}
    {--kind=operator_gateway_app-dev : Prepared topology kind to acquire}
    {--provider=incus : Topology provider (incus|docker)}
    {--checkout-roles= : Comma-separated roles to overlay the current checkout onto (defaults to every role in the kind)}')]
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
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>
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
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>
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

        if ($provider === 'docker') {
            return $this->renderError(
                'provider_acquisition_unavailable',
                'Retained docker topologies are not yet supported. Use --provider=incus.',
                $json,
            );
        }

        return $this->acquireIncus($kind, $displayRoles, $json);
    }

    /**
     * @param  list<string>  $displayRoles
     */
    private function acquireIncus(E2ETopologyKind $kind, array $displayRoles, bool $json): int
    {
        $config = E2EConfig::fromEnvironment();
        $overlayRoles = $this->overlayCheckoutRoles($kind, $displayRoles);

        try {
            $prepared = $this->prepareUsing !== null
                ? ($this->prepareUsing)($kind, $overlayRoles)
                : $this->acquireAndOverlay($config, $kind, $overlayRoles);
        } catch (Throwable $exception) {
            return $this->renderError('acquisition_failed', $exception->getMessage(), $json);
        }

        $manifest = [
            'id' => $prepared['run_id'],
            'kind' => $kind->value,
            'provider' => 'incus',
            'host' => $prepared['host'],
            'run_id' => $prepared['run_id'],
            'ssh_key_path' => $prepared['ssh_key_path'],
            'gateway_ip' => $prepared['gateway_ip'],
            'instances' => $prepared['instances'],
            'checkouts' => $prepared['checkouts'],
            'created_at' => now()->toIso8601String(),
        ];

        E2EDevTopologyManifestStore::fromEnvironment(repo_path())->write($manifest);

        return $this->renderAcquired($manifest, $json);
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
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>
     * }
     */
    private function acquireAndOverlay(E2EConfig $config, E2ETopologyKind $kind, array $overlayRoles): array
    {
        $runId = 'dev-'.bin2hex(random_bytes(3));
        $host = $this->resolveHost($config, $kind);
        $timer = new E2EPhaseTimer;

        try {
            $selection = E2ETopologyProviderPool::fromEnvironment($config)->select($kind);

            if (! $selection->available()) {
                throw new \RuntimeException('Prepared topology not available: '.$selection->message);
            }

            $lease = $selection->provider()->acquire(
                $kind,
                $runId,
                $timer,
                new E2ETopologyAcquisitionOptions(startGatewayApi: true),
            );
        } finally {
            $timer->flush('acquire');
        }

        $harness = new E2ETopologyHarness($lease, cleanupOnRelease: false);

        try {
            $harness->withCurrentCheckout($overlayRoles);
        } catch (Throwable $exception) {
            $this->reapAfterFailure($config, $host, $lease->instanceNames());

            throw $exception;
        }

        return [
            'host' => $host,
            'run_id' => $runId,
            'ssh_key_path' => $lease->sshKeyPair()->privateKeyPath,
            'gateway_ip' => $lease->gatewayApiIp(),
            'instances' => $this->instanceNamesByRole($lease, $overlayRoles),
            'checkouts' => $harness->checkouts(),
        ];
    }

    private function resolveHost(E2EConfig $config, E2ETopologyKind $kind): string
    {
        $availability = IncusHostPool::fromEnvironment($config)->availabilityFor($kind, checkCapacity: false);

        return $availability['host']?->config->host ?? $config->host;
    }

    /**
     * @param  list<string>  $names
     */
    private function reapAfterFailure(E2EConfig $config, string $host, array $names): void
    {
        if ($names === []) {
            return;
        }

        try {
            (new IncusHost($config->forHost($host)))->deleteInstancesIfPresent($names);
        } catch (Throwable) {
            // Surface the original overlay failure rather than the cleanup error.
        }
    }

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
     * @param  array{
     *     id: string,
     *     kind: string,
     *     provider: string,
     *     host: string,
     *     run_id: string,
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
        $releaseCommand = "composer e2e:dev-topology:release -- {$manifest['id']}";

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
        $this->line('Retained topologies are manual diagnosis tools; they are not standing infrastructure and must be released.');

        return self::SUCCESS;
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
        $handles = [];

        foreach ($manifest['instances'] as $role => $instance) {
            $user = $this->userForRole($role);
            $checkout = $manifest['checkouts'][$role] ?? "/home/{$user}/orbit";

            $handle = [
                'role' => $role,
                'instance' => $instance,
                'ssh_example' => sprintf(
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
                $handle['perf_example'] = sprintf(
                    'ssh %s incus exec %s -- bash -lc %s',
                    $host,
                    $instance,
                    escapeshellarg("curl -sS -o /dev/null -w 'gateway /api/ca/root: %{time_total}s\\n' http://{$manifest['gateway_ip']}/api/ca/root"),
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
        $payload = [
            'id' => 'dry-run',
            'dry_run' => true,
            'provider' => $provider,
            'host' => 'dry-run',
            'kind' => $kind->value,
            'checkout_roles' => $displayRoles,
            'shell_command' => $shellCommand,
            'release_command' => 'composer e2e:dev-topology:release -- dry-run',
        ];

        if ($json) {
            $this->line(json_encode(['success' => ['dev_topology' => $payload]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Retained topology dry run');
        $this->line("Provider: {$provider}");
        $this->line("Kind: {$kind->value}");
        $this->line('Checkout roles: '.implode(', ', $displayRoles));
        $this->line("Shell: {$shellCommand}");
        $this->line('Release: '.$payload['release_command']);
        $this->line('Source-checkout E2E remains the normal feature loop; retained topologies are manual diagnosis only.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $displayRoles
     */
    private function shellCommand(E2ETopologyKind $kind, string $provider, array $displayRoles): string
    {
        $command = "composer e2e:dev-topology -- --kind={$kind->value} --provider={$provider}";

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
        $requested = $this->requestedCheckoutRoles();

        return $requested === []
            ? $this->displayCheckoutRolesForKind($kind)
            : $requested;
    }

    /**
     * @return list<string>
     */
    private function requestedCheckoutRoles(): array
    {
        $value = $this->option('checkout-roles');

        if (! is_string($value) || trim($value) === '') {
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
}
