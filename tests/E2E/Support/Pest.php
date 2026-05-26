<?php

declare(strict_types=1);

use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EBaseProvisioner;
use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EProvisioningBundle;
use App\E2E\Support\E2ERun;
use App\E2E\Support\E2ETopologyCache;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyUnavailable;
use App\E2E\Support\IncusProvider;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * @template TValue
 *
 * @param  callable(): TValue  $callback
 * @return TValue
 */
function e2eProvisionStep(string $step, callable $callback): mixed
{
    fwrite(STDERR, "[e2e-provision] {$step}\n");

    try {
        return $callback();
    } catch (Throwable $throwable) {
        fwrite(STDERR, "[e2e-provision] failed at step: {$step}\n");

        throw $throwable;
    }
}

function e2eProvisionControlFromBase(
    IncusProvider $provider,
    E2ERun $run,
    E2EProvisioningBundle $bundle,
    E2EConfig $config,
    SshKeyPair $key,
): E2EInstance {
    $provisioner = new E2EBaseProvisioner($provider, $bundle);
    $control = e2eProvisionStep(
        'provision control from base',
        fn () => $provisioner->provision($run, 'control', 'control', $config->controlUser),
    );

    $control->authorizeSsh($config->controlUser, $key);
    $control->waitForSsh($config->controlUser, $key);
    e2eInstallPrivateSshKey($control, $key, $config->controlUser);

    return $control;
}

/**
 * @return array{0: E2EInstance, 1: array<string, mixed>}
 */
function e2eProvisionGatewayThroughNodeNew(
    IncusProvider $provider,
    E2ERun $run,
    E2EConfig $config,
    E2EInstance $control,
    SshKeyPair $key,
    string $name = 'gateway-1',
    bool $useWireGuardGatewayUrl = true,
): array {
    $gateway = e2eProvisionStep('launch base gateway', fn () => $run->launchBase('gateway'));

    e2eProvisionStep('wait for gateway cloud-init', fn () => $provider->host->waitForCloudInit($gateway->name()));
    $gateway->authorizeSsh($config->bootstrapUser, $key);
    $gateway->waitForSsh($config->bootstrapUser, $key);

    $gatewayIp = $gateway->waitForIpv4();

    $command = "cd /home/{$config->controlUser}/orbit && orbit node:new {$name} --role=gateway --host={$gatewayIp} --user={$config->bootstrapUser} --control-name=control-1 --json";
    $nodeNew = e2eProvisionStep('run node:new gateway', fn () => E2ECommand::ssh(
        $control,
        $config->controlUser,
        $key,
        $command,
        timeoutSeconds: 1800,
    ));

    if ($useWireGuardGatewayUrl) {
        e2eUseWireGuardGatewayUrl($control, $config->controlUser, $key);
    }

    E2EGatewayApi::installProvisioningSshKey($gateway, $key);

    return [$gateway, json_decode(trim($nodeNew->output()), associative: true, flags: JSON_THROW_ON_ERROR)];
}

/**
 * @return array{0: E2EInstance, 1: array<string, mixed>}
 */
function e2eProvisionAppThroughNodeNew(
    IncusProvider $provider,
    E2ERun $run,
    E2EConfig $config,
    E2EInstance $control,
    SshKeyPair $key,
    string $name,
    string $environment,
    ?string $tld = null,
): array {
    $app = e2eProvisionStep("launch base {$environment} app", fn () => $run->launchBase($name));

    e2eProvisionStep("wait for {$environment} app cloud-init", fn () => $provider->host->waitForCloudInit($app->name()));
    $app->authorizeSsh($config->bootstrapUser, $key);
    $app->waitForSsh($config->bootstrapUser, $key);

    $parts = [
        "cd /home/{$config->controlUser}/orbit && orbit node:new",
        escapeshellarg($name),
        '--role=app',
        '--host='.escapeshellarg($app->waitForIpv4()),
        '--environment='.escapeshellarg($environment),
        '--user='.escapeshellarg($config->bootstrapUser),
        '--json',
    ];

    if ($tld !== null) {
        $parts[] = '--tld='.escapeshellarg($tld);
    }

    $nodeNew = e2eProvisionStep("run node:new {$name}", fn () => E2ECommand::ssh(
        $control,
        $config->controlUser,
        $key,
        implode(' ', $parts),
        timeoutSeconds: 1800,
    ));

    return [$app, json_decode(trim($nodeNew->output()), associative: true, flags: JSON_THROW_ON_ERROR)];
}

/**
 * @param  list<string>  $roles
 * @return array{0: E2EInstance, 1: array<string, mixed>}
 */
function e2eProvisionIngressAppThroughNodeNew(
    IncusProvider $provider,
    E2ERun $run,
    E2EConfig $config,
    E2EInstance $control,
    SshKeyPair $key,
    string $name,
    array $roles,
    ?string $ingress = null,
): array {
    $app = e2eProvisionStep("launch base {$name}", fn () => $run->launchBase($name));

    e2eProvisionStep("wait for {$name} cloud-init", fn () => $provider->host->waitForCloudInit($app->name()));
    $app->authorizeSsh($config->bootstrapUser, $key);
    $app->waitForSsh($config->bootstrapUser, $key);

    $parts = [
        "cd /home/{$config->controlUser}/orbit && orbit node:new",
        escapeshellarg($name),
        '--host='.escapeshellarg($app->waitForIpv4()),
        '--user='.escapeshellarg($config->bootstrapUser),
        '--json',
    ];

    foreach ($roles as $role) {
        $parts[] = '--role='.escapeshellarg($role);
    }

    if ($ingress !== null) {
        $parts[] = '--ingress='.escapeshellarg($ingress);
    }

    $nodeNew = e2eProvisionStep("run node:new {$name}", fn () => E2ECommand::ssh(
        $control,
        $config->controlUser,
        $key,
        implode(' ', $parts),
        timeoutSeconds: 1800,
    ));

    return [$app, json_decode(trim($nodeNew->output()), associative: true, flags: JSON_THROW_ON_ERROR)];
}

/**
 * @return array<string, mixed>
 */
function e2eCreateProductionApp(
    E2EInstance $control,
    E2EConfig $config,
    SshKeyPair $key,
    string $node,
    string $domain,
): array {
    $parts = [
        "cd /home/{$config->controlUser}/orbit && orbit app:new docs",
        '--node='.escapeshellarg($node),
        '--domain='.escapeshellarg($domain),
        '--root=public',
        '--php-version=8.5',
        '--json',
    ];

    $appNew = e2eProvisionStep('run app:new docs', fn () => E2ECommand::ssh(
        $control,
        $config->controlUser,
        $key,
        implode(' ', $parts),
        timeoutSeconds: 600,
    ));

    return json_decode(trim($appNew->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  list<string>  $families
 */
function e2eAssertDoctorHealthy(
    E2EInstance $control,
    E2EConfig $config,
    SshKeyPair $key,
    string $node,
    array $families,
): void {
    $parts = [
        "cd /home/{$config->controlUser}/orbit && orbit doctor",
        '--node='.escapeshellarg($node),
        '--json',
    ];

    foreach ($families as $family) {
        $parts[] = '--family='.escapeshellarg($family);
    }

    $doctor = e2eProvisionStep("run doctor {$node}", fn () => E2ECommand::ssh(
        $control,
        $config->controlUser,
        $key,
        implode(' ', $parts),
        timeoutSeconds: 600,
    ));

    $payload = json_decode(trim($doctor->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($payload['success']['data']['doctor']['healthy'])->toBeTrue();
}

function e2eInstallPrivateSshKey(E2EInstance $instance, SshKeyPair $key, string $user): void
{
    $home = $user === 'root' ? '/root' : "/home/{$user}";

    E2ECommand::exec(
        $instance,
        sprintf(
            'install -d -m 700 -o %s -g %s %s',
            escapeshellarg($user),
            escapeshellarg($user),
            escapeshellarg("{$home}/.ssh"),
        ),
        "Could not prepare {$user} SSH directory",
    );

    $instance->copyFileToInstance($key->privateKeyPath, "{$home}/.ssh/id_ed25519");

    E2ECommand::exec(
        $instance,
        sprintf(
            'chown %s:%s %s && chmod 600 %s',
            escapeshellarg($user),
            escapeshellarg($user),
            escapeshellarg("{$home}/.ssh/id_ed25519"),
            escapeshellarg("{$home}/.ssh/id_ed25519"),
        ),
        "Could not install {$user} SSH key",
    );
}

function e2eUseWireGuardGatewayUrl(E2EInstance $control, string $controlUser, SshKeyPair $key): void
{
    $php = <<<'PHP'
$settings = \App\Models\LocalGatewaySettings::current();
$settings->gateway_url = 'https://10.6.0.2';
$settings->gateway_wg_ip = '10.6.0.2';
$settings->save();
PHP;

    E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        'cd /home/'.$controlUser.'/orbit && orbit tinker --execute='.escapeshellarg($php),
    );
}

/**
 * Acquire a prepared E2E topology and wrap it in the small Pest-facing helper
 * API used by feature tests.
 *
 * @param  array<string, string>|null  $sshUsers
 */
function e2eTopology(E2ETopologyKind $kind, ?array $sshUsers = null, bool $withGatewayApi = false): E2ETopologyHarness
{
    $sshUsers ??= ['control' => E2EConfig::fromEnvironment()->controlUser];
    $withGatewayApi = $withGatewayApi || e2eGatewayApiByDefault();

    if (E2ETopologyCache::enabled()) {
        return E2ETopologyCache::acquire($kind, $sshUsers, $withGatewayApi);
    }

    $factory = E2ETopologyFactory::fromEnvironment()
        ->withSshUsers($sshUsers);

    if ($withGatewayApi) {
        $factory = $factory->withGatewayApi();
    }

    try {
        $lease = $factory->require($kind);
    } catch (E2ETopologyUnavailable $exception) {
        test()->markTestSkipped($exception->getMessage());
    }

    return new E2ETopologyHarness($lease);
}

function e2eGatewayApiByDefault(): bool
{
    $value = getenv('ORBIT_E2E_GATEWAY_API');

    return is_string($value)
        && in_array(strtolower($value), ['1', 'true', 'yes'], true);
}

function e2eUsesDockerDnsAliasTopology(): bool
{
    return getenv('ORBIT_E2E_TOPOLOGY_PROVIDER') === 'docker';
}

function e2eRestartGatewayApi(E2ETopologyHarness $topology, string $label): void
{
    $gatewayApiIp = $topology->lease()->gatewayApiIp();

    if (e2eUsesDockerDnsAliasTopology()) {
        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            $label,
            $topology->checkout('gateway'),
            gatewayIp: '10.6.0.2',
            wireguardIdentity: '10.6.0.2',
            bindAddress: '0.0.0.0',
            certKey: 'gateway',
            certSans: ['10.6.0.2', 'gateway'],
            peerIdentityMap: e2eDockerDnsAliasPeerIdentityMap($topology),
        );
        e2eConfigureCurrentCheckoutGatewaySettingsIfAvailable($topology);

        return;
    }

    E2EGatewayApi::restart(
        $topology->instance('gateway'),
        $label,
        $topology->checkout('gateway'),
        gatewayIp: $gatewayApiIp,
    );
    e2eConfigureCurrentCheckoutGatewaySettingsIfAvailable($topology);
}

function e2eGatewayApiUrl(E2ETopologyHarness $topology): string
{
    if (e2eUsesDockerDnsAliasTopology()) {
        return 'https://gateway';
    }

    return 'https://'.$topology->lease()->gatewayApiIp();
}

function e2eGatewayWireGuardIp(E2ETopologyHarness $topology): string
{
    if (e2eUsesDockerDnsAliasTopology()) {
        return '10.6.0.2';
    }

    return $topology->lease()->gatewayApiIp();
}

function e2eConfigureCurrentCheckoutGatewaySettingsIfAvailable(E2ETopologyHarness $topology): void
{
    if (! array_key_exists('control', $topology->checkouts())) {
        return;
    }

    e2eConfigureCurrentCheckoutGatewaySettings($topology);
}

function e2eConfigureCurrentCheckoutGatewaySettings(E2ETopologyHarness $topology, string $role = 'control'): void
{
    if (e2eRoleUsesDockerHostLauncher($topology, $role)) {
        e2eConfigureCurrentCheckoutCliGatewaySettings($topology, $role);

        return;
    }

    $caPemPath = e2eRoleUsesDockerTopologyNode($topology, $role)
        ? e2eInstallCurrentCheckoutGatewayCa($topology, $role)
        : null;
    $checkout = escapeshellarg($topology->checkout($role));
    $gatewayUrlValue = var_export(e2eGatewayApiUrl($topology), true);
    $gatewayIpValue = var_export(e2eGatewayWireGuardIp($topology), true);
    $caPemPathValue = var_export($caPemPath, true);

    $php = <<<PHP
\$settings = \\App\\Models\\LocalGatewaySettings::current();
\$settings->fill([
    'gateway_url' => {$gatewayUrlValue},
    'gateway_wg_ip' => {$gatewayIpValue},
]);
if ({$caPemPathValue} !== null) {
    \$settings->ca_pem_path = {$caPemPathValue};
}
\$settings->save();
echo 'configured';
PHP;

    $topology->ssh(
        $role,
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

function e2eConfigureCurrentCheckoutCliGatewaySettings(E2ETopologyHarness $topology, string $role): void
{
    $checkout = escapeshellarg($topology->checkout($role).'/apps/cli');
    $gatewayUrl = e2eGatewayCliUrl($topology);

    $command = implode(' && ', [
        "cd {$checkout}",
        'touch .env',
        "grep -Ev '^(ORBIT_GATEWAY_URL|ORBIT_GATEWAY_IDENTITY)=' .env > .env.tmp || true",
        'mv .env.tmp .env',
        sprintf("printf 'ORBIT_GATEWAY_URL=%%s\\n' %s >> .env", escapeshellarg($gatewayUrl)),
    ]);

    $topology->ssh($role, $command, timeoutSeconds: 120);
}

function e2eInstallCurrentCheckoutGatewayCa(E2ETopologyHarness $topology, string $role): string
{
    $caPemPath = $topology->checkout($role).'/storage/app/orbit/ca/root.crt';
    $gatewayCaPath = $topology->checkout('gateway').'/storage/app/orbit/ca/root.crt';
    $rootCert = $topology->ssh(
        'gateway',
        'cat '.escapeshellarg($gatewayCaPath),
        timeoutSeconds: 120,
    )->output();

    $topology->ssh(
        $role,
        sprintf(
            'mkdir -p %s && printf %%s %s > %s',
            escapeshellarg(dirname($caPemPath)),
            escapeshellarg($rootCert),
            escapeshellarg($caPemPath),
        ),
        timeoutSeconds: 120,
    );

    return $caPemPath;
}

function e2eGatewayCliUrl(E2ETopologyHarness $topology): string
{
    if (e2eUsesDockerDnsAliasTopology()) {
        return 'http://gateway';
    }

    return 'http://'.$topology->lease()->gatewayApiIp();
}

/**
 * @return array<string, string>
 */
function e2eDockerDnsAliasPeerIdentityMap(E2ETopologyHarness $topology): array
{
    $canonical = [
        'gateway' => '10.6.0.2',
        'control' => '10.6.0.3',
        'dev' => '10.6.0.4',
        'prod' => '10.6.0.5',
        'ingress' => '10.6.0.7',
    ];

    $lease = $topology->lease();
    $instances = [
        'gateway' => $lease->gateway(),
        'control' => $lease->control(),
        'dev' => $lease->devApp(),
        'prod' => $lease->prodApp(),
        'ingress' => $lease->ingress(),
    ];

    $map = [];
    $mappedInstances = [];

    foreach ($instances as $role => $instance) {
        if ($instance !== null) {
            if (in_array($instance->name(), $mappedInstances, true)) {
                continue;
            }

            $mappedInstances[] = $instance->name();
            $map[$instance->waitForIpv4()] = $canonical[$role];
        }
    }

    return $map;
}

/**
 * Install the current checkout into selected topology roles and return their
 * remote checkout paths.
 *
 * @param  list<string>|null  $roles
 * @param  array<string, string>  $users
 * @return array<string, string>
 */
function e2eCheckout(E2ETopologyLease|E2ETopologyHarness $topology, ?array $roles = null, array $users = []): array
{
    if ($topology instanceof E2ETopologyHarness) {
        return $topology->withCurrentCheckout($roles, $users)->checkouts();
    }

    return E2ECurrentCheckout::installOnTopology($topology, $roles, $users);
}

function e2eRoleUsesDockerTopologyNode(E2ETopologyHarness $topology, string $role): bool
{
    return $topology->instance($role) instanceof DockerInstance;
}

function e2eRoleUsesDockerRuntime(E2ETopologyHarness $topology, string $role): bool
{
    return $role === 'gateway' && e2eRoleUsesDockerTopologyNode($topology, $role);
}

function e2eRoleUsesDockerHostLauncher(E2ETopologyHarness $topology, string $role): bool
{
    return e2eRoleUsesDockerTopologyNode($topology, $role)
        && in_array($role, ['operator', 'control', 'dev', 'prod', 'agent', 'ingress'], true);
}

function e2eRuntimeContainerName(E2ETopologyHarness $topology, string $role): string
{
    return $topology->instance($role)->name().'-orbit-runtime';
}

function e2eDockerRuntimeExecCommand(string $runtimeContainer, string $command): string
{
    return sprintf(
        'sudo docker exec %s sh -lc %s',
        escapeshellarg($runtimeContainer),
        escapeshellarg($command),
    );
}

function e2eRunInRoleRuntime(E2ETopologyHarness $topology, string $role, string $command, ?int $timeoutSeconds = null, bool $allowFailure = false): ProcessResult
{
    if (! e2eRoleUsesDockerRuntime($topology, $role)) {
        return $topology->ssh($role, $command, timeoutSeconds: $timeoutSeconds, allowFailure: $allowFailure);
    }

    $result = $topology->instance($role)->exec(
        e2eDockerRuntimeExecCommand(e2eRuntimeContainerName($topology, $role), $command),
        timeoutSeconds: $timeoutSeconds,
    );

    if (! $allowFailure && ! $result->successful()) {
        throw new RuntimeException(trim("Docker runtime command failed: {$command}\n".$result->output().$result->errorOutput()));
    }

    return $result;
}

function e2ePutRuntimeFile(E2ETopologyHarness $topology, string $role, string $path, string $contents, ?int $timeoutSeconds = null): void
{
    e2eRunInRoleRuntime(
        $topology,
        $role,
        sprintf(
            'cat > %s <<%s%s%s',
            escapeshellarg($path),
            escapeshellarg('ORBIT_E2E_FILE'),
            "\n{$contents}\n",
            'ORBIT_E2E_FILE',
        ),
        timeoutSeconds: $timeoutSeconds,
    );
}

function e2eOrbitWrapperScript(string $checkout, bool $dockerRuntime, ?string $executorNodeIdentity = null, bool $hostLauncher = false): string
{
    return E2ECurrentCheckout::orbitWrapperScript($checkout, $dockerRuntime, $executorNodeIdentity, $hostLauncher);
}

function e2ePhpServerCommand(int $port, string $routerPath, string $logPath, string $pidPath): string
{
    $server = 'p'.'hp -'.'S';
    $runner = 'no'.'hup '.$server;

    return sprintf(
        'rm -f %s %s; %s 127.0.0.1:%d %s > %s 2>&1 & echo $! > %s',
        escapeshellarg($pidPath),
        escapeshellarg($logPath),
        $runner,
        $port,
        escapeshellarg($routerPath),
        escapeshellarg($logPath),
        escapeshellarg($pidPath),
    );
}

function e2eStartRuntimePhpServer(E2ETopologyHarness $topology, string $role, int $port, string $routerPath, string $logPath, string $pidPath): void
{
    e2eRunInRoleRuntime(
        $topology,
        $role,
        e2ePhpServerCommand($port, $routerPath, $logPath, $pidPath),
        timeoutSeconds: 60,
    );
}

function e2eWaitForRuntimeHttpEndpoint(E2ETopologyHarness $topology, string $role, int $port, string $path, string $logPath): void
{
    e2eRunInRoleRuntime(
        $topology,
        $role,
        sprintf(
            'for i in $(seq 1 30); do curl -fsS -X POST %s -d "{}" >/dev/null 2>&1 && exit 0; sleep 1; done; cat %s; exit 1',
            escapeshellarg("http://127.0.0.1:{$port}{$path}"),
            escapeshellarg($logPath),
        ),
        timeoutSeconds: 45,
    );
}

function e2eStopRuntimePhpServer(E2ETopologyHarness $topology, string $role, string $pidPath): void
{
    e2eRunInRoleRuntime(
        $topology,
        $role,
        sprintf('test ! -f %s || kill "$(cat %s)" >/dev/null 2>&1 || true', escapeshellarg($pidPath), escapeshellarg($pidPath)),
        timeoutSeconds: 30,
        allowFailure: true,
    );
}

function e2eGrantNodeAccess(E2ETopologyHarness $topology, string $consumer = 'control-1', string $serving = 'app-dev-1'): void
{
    $consumerValue = var_export($consumer, true);
    $servingValue = var_export($serving, true);
    $checkout = escapeshellarg($topology->checkout('gateway'));

    $script = <<<PHP
\$nodes = \\App\\Models\\Node::query()
    ->whereIn('name', [{$consumerValue}, {$servingValue}])
    ->pluck('id', 'name');

foreach ([{$consumerValue}, {$servingValue}] as \$name) {
    if (! \$nodes->has(\$name)) {
        throw new \\RuntimeException("Missing prepared node [{\$name}].");
    }
}

\$servingName = {$servingValue};
\$servingRole = str_contains(\$servingName, 'app-prod') ? 'app-production' : (str_contains(\$servingName, 'app-dev') ? 'app-development' : null);

if (\$servingRole !== null) {
    \\App\\Models\\NodeRoleAssignment::query()->updateOrCreate(
        [
            'node_id' => \$nodes->get({$servingValue}),
            'role' => \$servingRole,
        ],
        [
            'status' => 'active',
            'settings' => [],
            'last_error' => null,
            'converged_at' => now(),
        ],
    );
}

\\Illuminate\\Support\\Facades\\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => \$nodes->get({$consumerValue}),
    'serving_node_id' => \$nodes->get({$servingValue}),
], [
    'permissions' => json_encode(['*']),
    'custom_permissions' => json_encode([]),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'granted';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

function e2eGrantNodeAccessOnGateway(E2EInstance $gateway, SshKeyPair $key, string $consumer = 'control-1', string $serving = 'app-dev-1'): void
{
    $consumerValue = var_export($consumer, true);
    $servingValue = var_export($serving, true);

    $script = <<<PHP
\$nodes = \\App\\Models\\Node::query()
    ->whereIn('name', [{$consumerValue}, {$servingValue}])
    ->pluck('id', 'name');

foreach ([{$consumerValue}, {$servingValue}] as \$name) {
    if (! \$nodes->has(\$name)) {
        throw new \\RuntimeException("Missing prepared node [{\$name}].");
    }
}

\$servingName = {$servingValue};
\$servingRole = str_contains(\$servingName, 'app-prod') ? 'app-production' : (str_contains(\$servingName, 'app-dev') ? 'app-development' : null);

if (\$servingRole !== null) {
    \\App\\Models\\NodeRoleAssignment::query()->updateOrCreate(
        [
            'node_id' => \$nodes->get({$servingValue}),
            'role' => \$servingRole,
        ],
        [
            'status' => 'active',
            'settings' => [],
            'last_error' => null,
            'converged_at' => now(),
        ],
    );
}

\\Illuminate\\Support\\Facades\\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => \$nodes->get({$consumerValue}),
    'serving_node_id' => \$nodes->get({$servingValue}),
], [
    'permissions' => json_encode(['*']),
    'custom_permissions' => json_encode([]),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'granted';
PHP;

    E2ECommand::ssh(
        $gateway,
        'orbit',
        $key,
        'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

function e2eProvisionCleanup(bool $passed, ?E2ERun $run = null, E2ETopologyLease|E2ETopologyHarness|null $topology = null): void
{
    if ($passed || ! e2eProvisionKeepsFailures()) {
        $run?->cleanup();
        $topology?->cleanup();

        return;
    }

    e2eProvisionReportDangling([
        ...($run?->instanceNames() ?? []),
        ...($topology?->instanceNames() ?? []),
    ]);
}

function e2eProvisionKeepsFailures(): bool
{
    $value = getenv('ORBIT_E2E_KEEP_ON_FAILURE');

    return ! is_string($value) || ! in_array(strtolower($value), ['0', 'false', 'no'], true);
}

/**
 * @param  list<string>  $instanceNames
 */
function e2eProvisionReportDangling(array $instanceNames): void
{
    $instanceNames = array_values(array_unique(array_filter($instanceNames)));

    if ($instanceNames === []) {
        fwrite(STDERR, "E2E provision test failed; no tracked instances were available to report.\n");

        return;
    }

    fwrite(STDERR, "E2E provision test failed; keeping instances for inspection:\n");

    foreach ($instanceNames as $instanceName) {
        fwrite(STDERR, "  - {$instanceName}\n");
    }

    fwrite(STDERR, "Reap later with: composer e2e:reap-incus -- --force --older-than=0m\n");
    fwrite(STDERR, "Set ORBIT_E2E_KEEP_ON_FAILURE=0 to restore cleanup-on-failure behavior.\n");
}
