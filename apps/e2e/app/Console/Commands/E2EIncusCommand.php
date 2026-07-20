<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\LiveIncusLocalMachine;
use App\E2E\Support\LiveIncusStepTree;
use App\E2E\Support\SourceMountedCheckoutSyncer;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;
use Throwable;

#[Signature('e2e:incus
    {--start : Acquire a retained Incus topology}
    {--stop : Release a retained Incus topology}
    {--live : Acquire a retained Incus topology and mint a local operator WireGuard identity}
    {--sync : Refresh the current checkout source mount for a retained Incus topology}
    {--topology=operator_gateway_app-dev : Prepared topology kind to acquire}
    {--id= : Retained topology id to release or sync}
    {--all : Release every recorded retained topology}
    {--checkout-roles= : Comma-separated roles to overlay the current checkout onto}
    {--operator-name= : Operator identity name to mint for --live (defaults to mac-<id>)}
    {--gateway-name= : Local gateway entry name for --live follow-up commands (defaults to incus-<id>)}
    {--wireguard-endpoint= : Public or LAN WireGuard endpoint to write into the --live config}
    {--manual : Generate the live WireGuard config and instructions without starting wg-quick or adding the local gateway}
    {--dry-run : Render the acquisition plan without provisioning a topology}
    {--json : Output as JSON}')]
#[Description('Start, expose, or stop retained Incus topologies for manual diagnosis')]
class E2EIncusCommand extends Command
{
    /** @var list<string> */
    private const array RetainedGatewayApiContainers = [
        'orbit-gateway-e2e-topology-lease-http',
        'orbit-gateway-e2e-topology-lease-tls',
    ];

    private const string WireGuardEndpointEnv = 'ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT';

    private const string WireGuardEndpointAliasEnv = 'ORBIT_E2E_LIVE_WG_ENDPOINT';

    /** @var (Closure(string): IncusHost)|null */
    private ?Closure $hostFactory = null;

    /** @var (Closure(): LiveIncusLocalMachine)|null */
    private ?Closure $localMachineFactory = null;

    /**
     * Override the Incus host factory for command tests.
     *
     * @param  Closure(string): IncusHost  $factory
     */
    public function hostFactoryUsing(Closure $factory): void
    {
        $this->hostFactory = $factory;
    }

    /**
     * Override the local machine adapter for command tests.
     *
     * @param  Closure(): LiveIncusLocalMachine  $factory
     */
    public function localMachineUsing(Closure $factory): void
    {
        $this->localMachineFactory = $factory;
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $start = (bool) $this->option('start');
        $stop = (bool) $this->option('stop');
        $live = (bool) $this->option('live');
        $sync = (bool) $this->option('sync');

        if (count(array_filter([$start, $stop, $live, $sync])) !== 1) {
            return $this->renderError(
                'validation_failed',
                'Choose exactly one Incus topology action: --start, --stop, --live, or --sync.',
                $json,
            );
        }

        if ($start) {
            return $this->start();
        }

        if ($live) {
            return $this->live();
        }

        if ($sync) {
            return $this->sync();
        }

        return $this->stop();
    }

    private function start(): int
    {
        $parameters = [
            '--provider' => 'incus',
            '--kind' => (string) $this->option('topology'),
            '--json' => (bool) $this->option('json'),
            '--dry-run' => (bool) $this->option('dry-run'),
        ];

        $checkoutRoles = $this->option('checkout-roles');

        if (is_string($checkoutRoles) && trim($checkoutRoles) !== '') {
            $parameters['--checkout-roles'] = $checkoutRoles;
        }

        return $this->call('e2e:dev-topology', $parameters);
    }

    private function stop(): int
    {
        $json = (bool) $this->option('json');

        try {
            $store = E2EDevTopologyManifestStore::fromEnvironment(repo_path());

            foreach ($this->manifestsForStop($store) as $manifest) {
                $this->stopLocalTunnelIfRecorded($manifest);
            }
        } catch (Throwable $exception) {
            return $this->renderLiveException($exception, $json);
        }

        $parameters = [
            '--json' => $json,
            '--all' => (bool) $this->option('all'),
        ];

        $id = $this->option('id');

        if (is_string($id) && trim($id) !== '') {
            $parameters['id'] = $id;
        }

        return $this->call('e2e:dev-topology:release', $parameters);
    }

    private function sync(): int
    {
        $json = (bool) $this->option('json');
        $id = $this->stringOption('id');

        if ($id === null) {
            return $this->renderError('validation_failed', 'A retained topology id is required for --sync.', $json);
        }

        $store = E2EDevTopologyManifestStore::fromEnvironment(repo_path());
        $manifest = $store->read($id);

        if ($manifest === null) {
            return $this->renderError(
                'retained_topology_not_found',
                "No retained topology manifest found for [{$id}].",
                $json,
            );
        }

        $provider = $manifest['provider'] ?? null;

        if ($provider !== 'incus') {
            $providerName = is_string($provider) && $provider !== '' ? $provider : 'unknown';

            return $this->renderError(
                'validation_failed',
                "Retained topology [{$id}] is a [{$providerName}] topology, not an Incus topology.",
                $json,
            );
        }

        $host = $manifest['host'] ?? null;

        if (! is_string($host) || trim($host) === '') {
            return $this->renderError(
                'validation_failed',
                "Retained topology [{$id}] does not record an Incus host.",
                $json,
            );
        }

        $host = trim($host);

        try {
            $sourcePath = $this->validatedRetainedSourcePath($manifest, $id, $host);
        } catch (Throwable $exception) {
            return $this->renderError('validation_failed', $exception->getMessage(), $json);
        }

        try {
            $runtimeCheckouts = $this->synchronizeRuntimeCheckouts(
                $store,
                $host,
                $id,
                $sourcePath,
            );
        } catch (Throwable $exception) {
            return $this->renderError('source_sync_failed', $exception->getMessage(), $json);
        }

        return $this->renderSourceSync(
            $this->sourceSyncPayload($manifest, $host, $sourcePath, $runtimeCheckouts),
            $json,
        );
    }

    private function live(): int
    {
        $json = (bool) $this->option('json');
        $manual = (bool) $this->option('manual');

        if ((bool) $this->option('dry-run')) {
            return $this->renderError(
                'validation_failed',
                '--live cannot be combined with --dry-run because no topology or operator identity would be created.',
                $json,
            );
        }

        $endpoint = $this->wireGuardEndpoint();

        if ($endpoint === null) {
            return $this->renderError(
                'validation_failed',
                'Set ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT in .env.e2e, or pass --wireguard-endpoint=<host:port>, before using --live.',
                $json,
            );
        }

        $kindInput = (string) $this->option('topology');
        $kind = E2ETopologyKind::tryFromInput($kindInput);

        if ($kind === null) {
            return $this->renderError('validation_failed', "Unsupported E2E topology kind [{$kindInput}].", $json);
        }

        if (! $manual && ! $this->localMachine()->hasWireGuardTools()) {
            return $this->renderError(
                'local_wireguard_unavailable',
                'Install wg and wg-quick before running live Incus local setup, or pass --manual.',
                $json,
            );
        }

        if (! $json) {
            new LiveIncusStepTree()->renderInitial($this->output, 'Preparing live Incus topology', [
                'Validate live endpoint',
                'Acquire topology',
                'Mint local operator identity',
                'Write WireGuard config',
                'Start local tunnel',
                'Add local gateway',
                'Verify gateway API',
            ]);
        }

        try {
            $devTopology = app(E2EDevTopologyCommand::class);
            $displayRoles = $devTopology->displayCheckoutRolesForInput($kind, $this->stringOption('checkout-roles'));
            $manifest = $devTopology->acquireRetainedIncusTopology($kind, $displayRoles);
            $operatorName = $this->nodeName($this->stringOption('operator-name') ?? "mac-{$manifest['id']}");
            $gatewayName = $this->nodeName($this->stringOption('gateway-name') ?? "incus-{$manifest['id']}");
            $enrollment = $this->mintOperatorIdentity($manifest, $operatorName);
            $wireGuardConfig = $this->rewriteWireGuardEndpoint($this->wireGuardConfig($enrollment), $endpoint);
            $wireGuardInterface = $this->wireGuardInterfaceName($manifest['id']);
            $wireGuardConfigPath = $this->writeWireGuardConfig($wireGuardInterface, $wireGuardConfig);
            $localSetup = $manual
                ? $this->manualLocalSetup()
                : $this->startLocalSetup(
                    $wireGuardInterface,
                    $wireGuardConfigPath,
                    $manifest['gateway_ip'],
                    $gatewayName,
                );
            $payload = $this->livePayload(
                $manifest,
                $operatorName,
                $gatewayName,
                $endpoint,
                $wireGuardInterface,
                $wireGuardConfig,
                $wireGuardConfigPath,
                $localSetup,
                $enrollment,
            );
            $this->writeLiveManifest($manifest, $payload, $operatorName, $gatewayName);
        } catch (Throwable $exception) {
            return $this->renderLiveException($exception, $json);
        }

        return $this->renderLive($payload, $json);
    }

    /**
     * @param  array{
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
     *     created_at: string,
     *     release_command: string,
     *     timings?: list<array{name: string, seconds: float}>,
     *     network?: string,
     *     managed_containers?: list<string>,
     *     volumes?: list<string>
     * }  $manifest
     * @return array<string, mixed>
     */
    private function mintOperatorIdentity(array $manifest, string $operatorName): array
    {
        $instance = $manifest['instances']['operator'] ?? null;

        if (! is_string($instance) || $instance === '') {
            throw new RuntimeException(
                'Live Incus setup requires an operator instance in the retained topology manifest.',
            );
        }

        $hostName = $manifest['host'];
        $config = E2EConfig::fromEnvironment()->forHost($hostName);
        $checkout = $manifest['checkouts']['operator'] ?? "/home/{$config->operatorUser}/orbit-current";
        $inner = 'cd '.escapeshellarg($checkout).' && orbit node:new '.$operatorName.' --operator --json';
        $result = $this->hostFor($hostName)->run(sprintf(
            'incus exec %s -- sudo -u %s bash -lc %s',
            escapeshellarg($instance),
            escapeshellarg($config->operatorUser),
            escapeshellarg($inner),
        ), timeoutSeconds: $config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException(
                trim($result->errorOutput())
                ?: "Could not mint operator identity [{$operatorName}] inside [{$instance}].",
            );
        }

        return $this->parseOperatorEnrollment($result->output());
    }

    private function hostFor(string $host): IncusHost
    {
        if ($this->hostFactory !== null) {
            return ($this->hostFactory)($host);
        }

        return new IncusHost(E2EConfig::fromEnvironment()->forHost($host));
    }

    private function localMachine(): LiveIncusLocalMachine
    {
        if ($this->localMachineFactory !== null) {
            return ($this->localMachineFactory)();
        }

        return new LiveIncusLocalMachine;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOperatorEnrollment(string $output): array
    {
        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($decoded)) {
                continue;
            }

            $enrollment = $this->extractOperatorEnrollment($decoded);

            if ($enrollment !== null) {
                return $enrollment;
            }

            $error = $this->extractOperatorEnrollmentError($decoded);

            if ($error !== null) {
                throw new RuntimeException($error);
            }
        }

        throw new RuntimeException('Could not parse operator identity output from orbit node:new --operator --json.');
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>|null
     */
    private function extractOperatorEnrollment(array $decoded): ?array
    {
        $direct = $decoded['success']['data'] ?? null;

        if (is_array($direct) && isset($direct['wireguard'])) {
            return $direct;
        }

        if (($decoded['event'] ?? null) !== 'complete') {
            return null;
        }

        $data = $decoded['data'] ?? null;
        $result = is_array($data) ? $data['data']['result'] ?? $data['result'] ?? null : null;

        if (is_array($result)) {
            $resultData = $result['success']['data'] ?? null;

            if (is_array($resultData) && isset($resultData['wireguard'])) {
                return $resultData;
            }

            if (isset($result['wireguard'])) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function extractOperatorEnrollmentError(array $decoded): ?string
    {
        $directError = $decoded['error']['message'] ?? null;

        if (is_string($directError) && trim($directError) !== '') {
            return $directError;
        }

        if (($decoded['event'] ?? null) !== 'error') {
            return null;
        }

        $data = $decoded['data'] ?? [];

        if (! is_array($data)) {
            return 'Operator identity minting failed.';
        }

        $message = $data['message'] ?? $data['data']['message'] ?? null;

        return is_string($message) && trim($message) !== ''
            ? $message
            : 'Operator identity minting failed.';
    }

    /**
     * @param  array<string, mixed>  $enrollment
     */
    private function wireGuardConfig(array $enrollment): string
    {
        $config = $enrollment['wireguard']['config'] ?? null;

        if (! is_string($config) || trim($config) === '') {
            throw new RuntimeException('Operator identity output did not include a WireGuard configuration.');
        }

        return trim($config)."\n";
    }

    private function rewriteWireGuardEndpoint(string $config, string $endpoint): string
    {
        $rewritten = preg_replace('/^Endpoint\s*=.*$/m', "Endpoint = {$endpoint}", $config, 1, $count);

        if ($rewritten === null || $count !== 1) {
            throw new RuntimeException('Operator WireGuard configuration did not include an Endpoint line to rewrite.');
        }

        return $rewritten;
    }

    private function writeWireGuardConfig(string $interface, string $config): string
    {
        $store = E2EDevTopologyManifestStore::fromEnvironment(repo_path());
        $directory = $store->directory();

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/'.$this->fileName($interface).'.conf';
        $written = file_put_contents($path, $config);

        if ($written === false) {
            throw new RuntimeException("Could not write WireGuard config to [{$path}].");
        }

        chmod($path, 0600);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $enrollment
     * @return array<string, mixed>
     */
    private function livePayload(
        array $manifest,
        string $operatorName,
        string $gatewayName,
        string $endpoint,
        string $wireGuardInterface,
        string $wireGuardConfig,
        string $wireGuardConfigPath,
        array $localSetup,
        array $enrollment,
    ): array {
        $gatewayAddCommand = "orbit gateway:add {$manifest['gateway_ip']} --name={$gatewayName}";
        $gatewayUseCommand = "orbit gateway:use {$gatewayName}";
        $releaseCommand = "composer e2e:incus -- --stop --id={$manifest['id']}";

        return [
            ...$manifest,
            'operator_node' => $operatorName,
            'operator_wireguard_ip' => $this->operatorWireGuardIp($enrollment),
            'wireguard_endpoint' => $endpoint,
            'wireguard_config_path' => $wireGuardConfigPath,
            'wireguard_config' => $wireGuardConfig,
            'wireguard' => [
                'endpoint' => $endpoint,
                'interface' => $wireGuardInterface,
                'real_interface' => $localSetup['real_interface'],
                'config_path' => $wireGuardConfigPath,
                'started' => $localSetup['started'],
                'gateway_added' => $localSetup['gateway_added'],
                'verified' => $localSetup['verified'],
            ],
            'gateway_name' => $gatewayName,
            'gateway_add_command' => $gatewayAddCommand,
            'gateway_use_command' => $gatewayUseCommand,
            'release_command' => $releaseCommand,
            'commands' => [
                'stop' => $releaseCommand,
                'gateway_check' => 'orbit node:list --json',
            ],
            'next_steps' => [
                $localSetup['started'] === true
                    ? "WireGuard tunnel [{$wireGuardInterface}] is active."
                    : "Run `wg-quick up {$wireGuardConfigPath}`.",
                $localSetup['gateway_added'] === true
                    ? "Local gateway [{$gatewayName}] is active."
                    : "Run `{$gatewayAddCommand}`.",
                'Verify access with `orbit gateway:list` or `orbit node:list --json`.',
                "Release the topology with `{$releaseCommand}` when you are done.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $enrollment
     */
    private function operatorWireGuardIp(array $enrollment): ?string
    {
        $wireGuardIp = $enrollment['node']['addresses']['wireguard'] ?? null;

        return is_string($wireGuardIp) && $wireGuardIp !== '' ? $wireGuardIp : null;
    }

    /**
     * @return array{
     *     mode: string,
     *     started: bool,
     *     gateway_added: bool,
     *     real_interface: string|null,
     *     verified: bool
     * }
     */
    private function startLocalSetup(
        string $interface,
        string $configPath,
        string $gatewayIp,
        string $gatewayName,
    ): array {
        $machine = $this->localMachine();

        if (! $machine->hasWireGuardTools()) {
            throw new RuntimeException(
                'local_wireguard_unavailable: Install wg and wg-quick before running live Incus local setup, or pass --manual.',
            );
        }

        $startedByCommand = false;
        $realInterface = $machine->realWireGuardInterface($interface);

        if ($realInterface === null) {
            $start = $machine->startWireGuard($configPath);

            if (! $start->successful()) {
                throw new RuntimeException('local_wireguard_failed: '.$this->processError($start));
            }

            $startedByCommand = true;
            $realInterface = $machine->realWireGuardInterface($interface);
        }

        $interfaces = $machine->wireGuardInterfaces();

        if ($realInterface === null || ! in_array($realInterface, $interfaces, true)) {
            $this->stopStartedWireGuardAfterFailure($machine, $startedByCommand, $configPath);

            throw new RuntimeException(
                'local_wireguard_failed: wg-quick started but the tunnel was not visible through wg show interfaces.',
            );
        }

        $gateway = $machine->addGateway($gatewayIp, $gatewayName);

        if (! $gateway->successful()) {
            $this->stopStartedWireGuardAfterFailure($machine, $startedByCommand, $configPath);

            throw new RuntimeException('local_gateway_failed: '.$this->processError($gateway));
        }

        $verify = $machine->verifyGateway($gatewayIp);

        if (! $verify->successful()) {
            $this->stopStartedWireGuardAfterFailure($machine, $startedByCommand, $configPath);

            throw new RuntimeException('gateway_unreachable: '.$this->processError($verify));
        }

        return [
            'mode' => 'wg-quick',
            'started' => true,
            'gateway_added' => true,
            'real_interface' => $realInterface,
            'verified' => true,
        ];
    }

    /**
     * @return array{
     *     mode: string,
     *     started: bool,
     *     gateway_added: bool,
     *     real_interface: string|null,
     *     verified: bool
     * }
     */
    private function manualLocalSetup(): array
    {
        return [
            'mode' => 'manual',
            'started' => false,
            'gateway_added' => false,
            'real_interface' => null,
            'verified' => false,
        ];
    }

    private function stopStartedWireGuardAfterFailure(
        LiveIncusLocalMachine $machine,
        bool $startedByCommand,
        string $configPath,
    ): void {
        if (! $startedByCommand) {
            return;
        }

        $machine->stopWireGuard($configPath);
    }

    private function processError(ProcessResult $result): string
    {
        $output = trim($result->errorOutput() ?: $result->output());

        return $output !== '' ? $output : 'Command failed without output.';
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $payload
     */
    private function writeLiveManifest(array $manifest, array $payload, string $operatorName, string $gatewayName): void
    {
        E2EDevTopologyManifestStore::fromEnvironment(repo_path())->write([
            ...$manifest,
            'live' => [
                'operator_node' => $operatorName,
                'wireguard' => $payload['wireguard'],
                'gateway' => [
                    'name' => $gatewayName,
                    'ip' => $manifest['gateway_ip'],
                    'added' => $payload['wireguard']['gateway_added'],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function sourceSyncPayload(
        array $manifest,
        string $host,
        string $sourcePath,
        array $runtimeCheckouts = [],
    ): array {
        $id = (string) ($manifest['id'] ?? '');

        return [
            'id' => $id,
            'kind' => (string) ($manifest['kind'] ?? ''),
            'provider' => 'incus',
            'host' => $host,
            'source_path' => $sourcePath,
            'checkouts' => is_array($manifest['checkouts'] ?? null) ? $manifest['checkouts'] : [],
            'runtime_checkouts' => $runtimeCheckouts,
            'sync_command' => "composer e2e:incus -- --sync --id={$id}",
            'release_command' => "composer e2e:incus -- --stop --id={$id}",
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function refreshRuntimeCheckouts(array $manifest, string $host): array
    {
        $runtimeCheckouts = [];
        $failures = [];

        foreach ($this->runtimeCheckoutTargets($manifest, $host) as $runtime) {
            try {
                $runtimeCheckouts[$runtime['role']] = $this->mountFreshRuntimeCheckout($runtime, $host);
            } catch (Throwable $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        if ($failures !== []) {
            throw new RuntimeException('Runtime checkout refresh failed: '.implode(' | ', $failures));
        }

        return $runtimeCheckouts;
    }

    /** @param array<string, mixed> $manifest */
    private function unmountRuntimeCheckouts(array $manifest, string $host): void
    {
        $unmounted = [];

        foreach ($this->runtimeCheckoutTargets($manifest, $host) as $runtime) {
            $result = $this->runRuntimeCheckoutCommand(
                $runtime,
                $host,
                E2ECurrentCheckout::sourceMountedRuntimeOverlayUnmountCommand($runtime['target_path']),
            );

            if ($result->successful()) {
                $unmounted[] = $runtime;

                continue;
            }

            $restoreFailures = $this->restoreRuntimeCheckouts($unmounted, $host);

            $restoreDetail = $restoreFailures === []
                ? ''
                : ' Previously unmounted checkouts also failed to restore: '.implode(' | ', $restoreFailures);

            throw new RuntimeException(
                "Could not unmount runtime checkout [{$runtime['target_path']}] in [{$runtime['instance']}]: "
                .$this->processError($result)
                .$restoreDetail,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{role: string, instance: string, user: string, target_path: string, timeout_seconds: int}>
     */
    private function runtimeCheckoutTargets(array $manifest, string $host): array
    {
        $instances = is_array($manifest['instances'] ?? null) ? $manifest['instances'] : [];
        $checkouts = is_array($manifest['checkouts'] ?? null) ? $manifest['checkouts'] : [];
        $config = E2EConfig::fromEnvironment()->forHost($host);
        $targets = [];

        foreach ($checkouts as $role => $checkout) {
            if (! is_string($role) || ! is_string($checkout) || trim($checkout) === '') {
                continue;
            }

            $instance = $instances[$role] ?? null;

            if (! is_string($instance) || trim($instance) === '') {
                continue;
            }

            $user = $this->runtimeCheckoutUser($role, $config);
            $targetPath = rtrim($checkout, '/');

            if ($targetPath === E2ECurrentCheckout::sourceMountedGuestPath()) {
                $targetPath = E2ECurrentCheckout::sourceMountedRuntimePath($user);
            }

            $targets[] = [
                'role' => $role,
                'instance' => trim($instance),
                'user' => $user,
                'target_path' => $targetPath,
                'timeout_seconds' => $config->timeoutSeconds,
            ];
        }

        return $targets;
    }

    /**
     * @param array{role: string, instance: string, user: string, target_path: string, timeout_seconds: int} $runtime
     */
    private function mountFreshRuntimeCheckout(array $runtime, string $host): string
    {
        return $this->mountRuntimeCheckout(
            $runtime,
            $host,
            E2ECurrentCheckout::sourceMountedRuntimeOverlayCommand(
                E2ECurrentCheckout::sourceMountedGuestPath(),
                $runtime['target_path'],
            ),
        );
    }

    /**
     * @param array{role: string, instance: string, user: string, target_path: string, timeout_seconds: int} $runtime
     */
    private function restoreRuntimeCheckout(array $runtime, string $host): string
    {
        return $this->mountRuntimeCheckout(
            $runtime,
            $host,
            E2ECurrentCheckout::sourceMountedRuntimeOverlayRestoreCommand(
                E2ECurrentCheckout::sourceMountedGuestPath(),
                $runtime['target_path'],
            ),
        );
    }

    /**
     * @param array{role: string, instance: string, user: string, target_path: string, timeout_seconds: int} $runtime
     */
    private function mountRuntimeCheckout(array $runtime, string $host, string $command): string
    {
        $result = $this->runRuntimeCheckoutCommand($runtime, $host, $command);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Could not refresh runtime checkout [{$runtime['target_path']}] in [{$runtime['instance']}]: "
                    .$this->processError($result),
            );
        }

        return $runtime['target_path'];
    }

    /**
     * @param array{role: string, instance: string, user: string, target_path: string, timeout_seconds: int} $runtime
     */
    private function runRuntimeCheckoutCommand(array $runtime, string $host, string $command): ProcessResult
    {
        return $this->hostFor($host)->run(sprintf(
            'incus exec %s -- sudo -u %s bash -lc %s',
            escapeshellarg($runtime['instance']),
            escapeshellarg($runtime['user']),
            escapeshellarg($command),
        ), timeoutSeconds: $runtime['timeout_seconds']);
    }

    /**
     * @param  list<array{role: string, instance: string, user: string, target_path: string, timeout_seconds: int}>  $runtimes
     * @return list<string>
     */
    private function restoreRuntimeCheckouts(array $runtimes, string $host): array
    {
        $failures = [];

        foreach ($runtimes as $runtime) {
            try {
                $this->restoreRuntimeCheckout($runtime, $host);
            } catch (Throwable $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        return $failures;
    }

    /**
     * @return array<string, string>
     */
    private function synchronizeRuntimeCheckouts(
        E2EDevTopologyManifestStore $store,
        string $host,
        string $id,
        string $sourcePath,
    ): array {
        $runtimeCheckouts = [];
        $syncer = new SourceMountedCheckoutSyncer;

        $syncedPath = self::sourcePathResult($syncer->withSyncLock(
            host: $host,
            provider: 'incus',
            criticalSection: function (Closure $syncSource) use (
                $store,
                $host,
                $id,
                $sourcePath,
                &$runtimeCheckouts,
            ): string {
                $manifest = $this->currentRetainedManifestForSync($store, $id, $host, $sourcePath);
                $this->unmountRuntimeCheckouts($manifest, $host);

                try {
                    $syncedPath = $syncSource();
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        $exception->getMessage()
                            .' Runtime checkouts remain detached so an incomplete source tree is not exposed. Retry the same sync command.',
                        previous: $exception,
                    );
                }

                $syncedPath = self::sourcePathResult($syncedPath);

                $runtimeCheckouts = $this->refreshRuntimeCheckouts($manifest, $host);
                $this->restartRetainedGatewayApi($manifest, $host, $runtimeCheckouts);

                return $syncedPath;
            },
            scope: $id,
            sourcePath: $sourcePath,
        ));

        if (! hash_equals($sourcePath, $syncedPath)) {
            throw new RuntimeException(
                "Source sync returned [{$syncedPath}] instead of recorded path [{$sourcePath}].",
            );
        }

        return $runtimeCheckouts;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $runtimeCheckouts
     */
    private function restartRetainedGatewayApi(array $manifest, string $host, array $runtimeCheckouts): void
    {
        if (! isset($runtimeCheckouts['gateway'])) {
            return;
        }

        $instances = is_array($manifest['instances'] ?? null) ? $manifest['instances'] : [];
        $gateway = $instances['gateway'] ?? null;

        if (! is_string($gateway) || trim($gateway) === '') {
            throw new RuntimeException(
                'A gateway runtime checkout requires a gateway instance in the retained topology manifest.',
            );
        }

        $result = $this->hostFor($host)->run(sprintf(
            'incus exec %s -- docker restart %s',
            escapeshellarg($gateway),
            implode(' ', array_map(escapeshellarg(...), self::RetainedGatewayApiContainers)),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Could not restart the retained gateway API after refreshing its runtime checkout: '
                    .$this->processError($result),
            );
        }

        $migration = $this->hostFor($host)->run(sprintf(
            'incus exec %s -- docker exec %s php artisan migrate --force --no-interaction --no-ansi',
            escapeshellarg($gateway),
            escapeshellarg(self::RetainedGatewayApiContainers[0]),
        ));

        if (! $migration->successful()) {
            throw new RuntimeException(
                'Could not migrate the retained gateway after refreshing its runtime checkout: '
                    .$this->processError($migration),
            );
        }
    }

    private static function sourcePathResult(mixed $result): string
    {
        if (! is_string($result)) {
            throw new \LogicException('The source sync operation must return its source path.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function currentRetainedManifestForSync(
        E2EDevTopologyManifestStore $store,
        string $id,
        string $host,
        string $sourcePath,
    ): array {
        $manifest = $store->read($id);

        if ($manifest === null) {
            throw new RuntimeException(
                "Retained topology [{$id}] was released while source sync waited for its lifecycle lock.",
            );
        }

        $currentHost = is_string($manifest['host'] ?? null) ? trim($manifest['host']) : '';
        $provider = $manifest['provider'] ?? null;
        $currentSourcePath = $this->validatedRetainedSourcePath($manifest, $id, $currentHost);

        if (
            $provider !== 'incus'
            || ! hash_equals($host, $currentHost)
            || ! hash_equals($sourcePath, $currentSourcePath)
        ) {
            throw new RuntimeException(
                "Retained topology [{$id}] changed while source sync waited for its lifecycle lock.",
            );
        }

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function validatedRetainedSourcePath(array $manifest, string $id, string $host): string
    {
        $manifestId = is_string($manifest['id'] ?? null) ? trim($manifest['id']) : '';
        $runId = is_string($manifest['run_id'] ?? null) ? trim($manifest['run_id']) : '';

        if ($manifestId !== $id || $runId !== $id) {
            throw new RuntimeException(
                "Retained topology identity mismatch: requested [{$id}], manifest id [{$manifestId}], run id [{$runId}].",
            );
        }

        $sourcePath = is_string($manifest['source_path'] ?? null) ? trim($manifest['source_path']) : '';

        if ($sourcePath === '') {
            throw new RuntimeException(
                "Retained topology [{$id}] predates isolated source paths; release and reacquire it before syncing.",
            );
        }

        $expectedPath = new SourceMountedCheckoutSyncer()->sourcePath($host, 'incus', $id);

        if (! hash_equals($expectedPath, $sourcePath)) {
            throw new RuntimeException(
                "Retained topology [{$id}] records unexpected source path [{$sourcePath}]; expected [{$expectedPath}].",
            );
        }

        return $sourcePath;
    }

    private function runtimeCheckoutUser(string $role, E2EConfig $config): string
    {
        return $role === 'operator'
            ? $config->operatorUser
            : 'orbit';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderSourceSync(array $payload, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'success' => [
                    'source_sync' => $payload,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Synced retained Incus topology [{$payload['id']}].");
        $this->line("Kind: {$payload['kind']}");
        $this->line("Host: {$payload['host']}");
        $this->line("Source path: {$payload['source_path']}");
        $this->line('Mounted checkouts:');

        foreach ($payload['checkouts'] as $role => $checkout) {
            if (! is_string($role) || ! is_string($checkout)) {
                continue;
            }

            $this->line("- {$role}: {$checkout}");
        }

        if ($payload['runtime_checkouts'] !== []) {
            $this->line('Runtime checkouts:');

            foreach ($payload['runtime_checkouts'] as $role => $checkout) {
                if (! is_string($role) || ! is_string($checkout)) {
                    continue;
                }

                $this->line("- {$role}: {$checkout}");
            }
        }

        $this->line("Sync again: {$payload['sync_command']}");
        $this->line("Release: {$payload['release_command']}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderLive(array $payload, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'success' => [
                    'live_topology' => $payload,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        new LiveIncusStepTree()->renderComplete($this->output, "Live Incus topology [{$payload['id']}] is ready.");
        $this->line("Kind: {$payload['kind']}");
        $this->line("Provider: {$payload['provider']} (host {$payload['host']})");
        $this->line("Gateway API: http://{$payload['gateway_ip']}");
        $this->line("Operator identity: {$payload['operator_node']}");

        if (is_string($payload['operator_wireguard_ip'] ?? null)) {
            $this->line("Operator WireGuard IP: {$payload['operator_wireguard_ip']}");
        }

        $this->line("WireGuard endpoint: {$payload['wireguard_endpoint']}");
        $this->line("WireGuard interface: {$payload['wireguard']['interface']}");

        if (is_string($payload['wireguard']['real_interface'] ?? null)) {
            $this->line("WireGuard real interface: {$payload['wireguard']['real_interface']}");
        }

        $this->line("WireGuard config path: {$payload['wireguard_config_path']}");
        $this->line("Local gateway: {$payload['gateway_name']}");
        $this->line("Gateway check: {$payload['commands']['gateway_check']}");
        $this->line('');
        $this->line('WireGuard config');
        $this->line('---');
        $this->line(rtrim((string) $payload['wireguard_config']));
        $this->line('---');
        $this->line('');
        $this->line('Next steps:');

        foreach ($payload['next_steps'] as $index => $step) {
            $number = $index + 1;
            $this->line("{$number}. {$step}");
        }

        $this->line('');
        $this->line("Release: {$payload['release_command']}");

        return self::SUCCESS;
    }

    private function wireGuardEndpoint(): ?string
    {
        $endpoint =
            $this->stringOption(
                'wireguard-endpoint',
            ) ?? $this->envString(self::WireGuardEndpointEnv) ?? $this->envString(self::WireGuardEndpointAliasEnv);

        if ($endpoint === null) {
            return null;
        }

        return trim($endpoint);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function envString(string $key): ?string
    {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function nodeName(string $value): string
    {
        $name = strtolower($value);
        $name = preg_replace('/[^a-z0-9_.-]+/', '-', $name) ?? $name;
        $name = trim($name, '-_.');

        if ($name === '') {
            throw new RuntimeException(
                'Live Incus operator and gateway names must contain at least one letter or number.',
            );
        }

        return $name;
    }

    private function wireGuardInterfaceName(string $topologyId): string
    {
        $suffix = preg_replace('/^dev-/', '', strtolower($topologyId)) ?? $topologyId;
        $suffix = preg_replace('/[^a-z0-9]/', '', $suffix) ?? $suffix;
        $suffix = substr($suffix, 0, 10);

        if ($suffix === '') {
            throw new RuntimeException(
                'Live Incus topology id must contain at least one letter or number for the WireGuard interface name.',
            );
        }

        return "oe2e{$suffix}";
    }

    private function fileName(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manifestsForStop(E2EDevTopologyManifestStore $store): array
    {
        if ((bool) $this->option('all')) {
            return $store->list();
        }

        $id = $this->stringOption('id');

        if ($id === null || $id === 'dry-run') {
            return [];
        }

        $manifest = $store->read($id);

        return $manifest === null ? [] : [$manifest];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function stopLocalTunnelIfRecorded(array $manifest): void
    {
        $wireGuard = $manifest['live']['wireguard'] ?? null;

        if (! is_array($wireGuard) || ($wireGuard['started'] ?? false) !== true) {
            return;
        }

        $interface = $wireGuard['interface'] ?? null;
        $configPath = $wireGuard['config_path'] ?? null;

        if (! is_string($interface) || ! is_string($configPath)) {
            return;
        }

        $machine = $this->localMachine();
        $realInterface = $machine->realWireGuardInterface($interface);

        if ($realInterface === null || ! in_array($realInterface, $machine->wireGuardInterfaces(), true)) {
            return;
        }

        $result = $machine->stopWireGuard($configPath);

        if (! $result->successful()) {
            throw new RuntimeException('local_wireguard_failed: '.$this->processError($result));
        }
    }

    private function renderLiveException(Throwable $exception, bool $json): int
    {
        $message = $exception->getMessage();

        foreach ([
            'local_wireguard_unavailable',
            'local_wireguard_failed',
            'local_gateway_failed',
            'gateway_unreachable',
        ] as $code) {
            if (str_starts_with($message, "{$code}:")) {
                return $this->renderError($code, trim(substr($message, strlen($code) + 1)), $json);
            }
        }

        return $this->renderError('live_setup_failed', $message, $json);
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
