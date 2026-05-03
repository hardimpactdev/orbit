<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2ERun;
use Tests\E2E\Support\ProviderPool;
use Tests\E2E\Support\SshKeyPair;

pest()->group('e2e-provisioning');

it('provisions a production app node from a ready control VM through a ready gateway VM', function (): void {
    $selection = ProviderPool::fromEnvironment()->select(E2EImage::Blank, E2EImage::Control, E2EImage::Gateway);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $provider = $selection->provider();
    $config = $provider->config();
    $run = E2ERun::start($provider, 'node-new-prodapp');

    try {
        $key = $run->createSshKeyPair();
        $control = $run->launchControl('control');
        $gateway = $run->launchGateway('gateway');
        $app = $run->launchBlank('app');

        $control->authorizeSsh($config->controlUser, $key);
        $gateway->authorizeSsh('orbit', $key);
        $app->authorizeSsh($config->bootstrapUser, $key);

        $control->waitForSsh($config->controlUser, $key);
        $gateway->waitForSsh('orbit', $key);
        $app->waitForSsh($config->bootstrapUser, $key);

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();
        $appIp = $app->waitForIpv4();

        expect($controlIp)->not->toBe($gatewayIp)
            ->and($controlIp)->not->toBe($appIp)
            ->and($gatewayIp)->not->toBe($appIp);

        nodeNewProductionAppE2EAssignWireGuardIp($control, '10.6.0.3');
        nodeNewProductionAppE2EAssignWireGuardIp($gateway, '10.6.0.2');
        nodeNewProductionAppE2ERouteWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
        nodeNewProductionAppE2ERouteWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');
        nodeNewProductionAppE2ESeedControlIdentity($gateway, $controlIp, $config->controlUser);
        nodeNewProductionAppE2EInstallGatewayRootSshKey($gateway, $key);
        nodeNewProductionAppE2EStartGatewayApi($gateway);
        nodeNewProductionAppE2EWaitForGatewayApi($control, $config->controlUser, $key);

        $gatewayAdd = nodeNewProductionAppE2ESsh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit gateway:add 10.6.0.2 --json",
            timeoutSeconds: 600,
        );

        $gatewayAddPayload = json_decode(trim($gatewayAdd->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($gatewayAddPayload['success']['data']['result']['action'])->toBe('added');

        $nodeNew = nodeNewProductionAppE2ESsh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit node:new app-prod-1 --role=app --host={$appIp} --environment=production --ssh-user={$config->bootstrapUser} --json",
            timeoutSeconds: 1800,
        );

        $payload = json_decode(trim($nodeNew->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['result']['action'])->toBe('created')
            ->and($payload['success']['data']['node']['name'])->toBe('app-prod-1')
            ->and($payload['success']['data']['node']['role'])->toBe('app')
            ->and($payload['success']['data']['node']['environment'])->toBe('production')
            ->and($payload['success']['data']['node']['tld'])->toBeNull()
            ->and($payload['success']['data']['node']['addresses']['wireguard'])->toBe('10.6.0.4')
            ->and($payload['success']['data'])->not->toHaveKey('development_tld');

        $gatewayNode = nodeNewProductionAppE2EGatewayNode($gateway, 'app-prod-1');

        expect($gatewayNode['name'])->toBe('app-prod-1')
            ->and($gatewayNode['role'])->toBe('app')
            ->and($gatewayNode['environment'])->toBe('production')
            ->and($gatewayNode['tld'])->toBeNull()
            ->and($gatewayNode['host'])->toBe($appIp)
            ->and($gatewayNode['wireguard_address'])->toBe('10.6.0.4')
            ->and($gatewayNode['gateway_endpoint'])->toBe('10.6.0.2')
            ->and($gatewayNode['ssh_user'])->toBe($config->bootstrapUser)
            ->and($gatewayNode['user'])->toBe('orbit')
            ->and((bool) $gatewayNode['is_local'])->toBeFalse();

        $appInstall = $app->exec('test -d /home/orbit/orbit && test -f /home/orbit/orbit/artisan');
        $appVersion = $app->exec("sudo -iu orbit bash -lc 'orbit --version | grep -F Orbit'");

        expect($appInstall->successful())->toBeTrue($appInstall->output().$appInstall->errorOutput())
            ->and($appVersion->successful())->toBeTrue($appVersion->output().$appVersion->errorOutput());

        $controlNodeCount = nodeNewProductionAppE2ESsh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && php artisan tinker --execute='echo \\App\\Models\\Node::query()->count();'",
        );

        expect(trim($controlNodeCount->output()))->toBe('0');
    } finally {
        $run->cleanup();
    }
});

function nodeNewProductionAppE2EAssignWireGuardIp(E2EInstance $instance, string $wireGuardIp): void
{
    nodeNewProductionAppE2EExec(
        $instance,
        sprintf(
            'iface="$(ip -o -4 route show default | awk \'{print $5; exit}\')"; test -n "$iface"; ip addr add %s dev "$iface" 2>/dev/null || true; ip link set "$iface" up',
            escapeshellarg("{$wireGuardIp}/16"),
        ),
        "Could not assign {$wireGuardIp} to {$instance->name()}",
    );
}

function nodeNewProductionAppE2ERouteWireGuardPeer(E2EInstance $instance, string $wireGuardIp, string $providerIp, string $sourceWireGuardIp): void
{
    nodeNewProductionAppE2EExec(
        $instance,
        sprintf(
            'iface="$(ip -o -4 route show default | awk \'{print $5; exit}\')"; test -n "$iface"; ip route replace %s via %s dev "$iface" src %s',
            escapeshellarg("{$wireGuardIp}/32"),
            escapeshellarg($providerIp),
            escapeshellarg($sourceWireGuardIp),
        ),
        "Could not route {$wireGuardIp} via {$providerIp} from {$instance->name()}",
    );
}

function nodeNewProductionAppE2ESeedControlIdentity(E2EInstance $gateway, string $controlIp, string $controlUser): void
{
    $controlIpValue = var_export($controlIp, true);
    $controlUserValue = var_export($controlUser, true);
    $orbitPathValue = var_export("/home/{$controlUser}/orbit", true);

    $php = <<<PHP
\\App\\Models\\Node::query()->updateOrCreate(
    ['name' => 'control-1'],
    [
        'role' => 'control',
        'environment' => null,
        'tld' => null,
        'platform' => 'unknown',
        'host' => {$controlIpValue},
        'wireguard_address' => '10.6.0.3',
        'gateway_endpoint' => '10.6.0.2',
        'ssh_user' => {$controlUserValue},
        'user' => {$controlUserValue},
        'orbit_path' => {$orbitPathValue},
        'status' => 'active',
        'is_local' => false,
    ],
);
PHP;

    nodeNewProductionAppE2EOrbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($php),
        'Could not seed control identity on gateway',
    );
}

function nodeNewProductionAppE2EInstallGatewayRootSshKey(E2EInstance $gateway, SshKeyPair $key): void
{
    nodeNewProductionAppE2EExec($gateway, 'install -d -m 700 /root/.ssh', 'Could not prepare root SSH directory on gateway');
    $gateway->copyFileToInstance($key->privateKeyPath, '/root/.ssh/id_ed25519');
    nodeNewProductionAppE2EExec($gateway, 'chmod 600 /root/.ssh/id_ed25519', 'Could not install root SSH key on gateway');
}

function nodeNewProductionAppE2EStartGatewayApi(E2EInstance $gateway): void
{
    nodeNewProductionAppE2EOrbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg("app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf('10.6.0.2'); echo 'issued';"),
        'Could not issue gateway leaf certificate',
    );

    nodeNewProductionAppE2EExec(
        $gateway,
        "cat > /tmp/orbit-node-new-prodapp-tls.php <<'PHP'\n".nodeNewProductionAppE2ETlsServerScript()."\nPHP",
        'Could not write gateway TLS test server',
    );

    nodeNewProductionAppE2EExec(
        $gateway,
        'cd /home/orbit/orbit && nohup php artisan serve --host=10.6.0.2 --port=80 > /tmp/orbit-gateway-http.log 2>&1 &',
        'Could not start gateway HTTP API',
    );

    nodeNewProductionAppE2EExec(
        $gateway,
        'nohup php /tmp/orbit-node-new-prodapp-tls.php > /tmp/orbit-gateway-tls.log 2>&1 &',
        'Could not start gateway TLS test server',
    );
}

function nodeNewProductionAppE2EWaitForGatewayApi(E2EInstance $control, string $controlUser, SshKeyPair $key): void
{
    $deadline = time() + 120;
    $last = null;
    $lastException = null;

    while (time() < $deadline) {
        try {
            $last = $control->ssh(
                $controlUser,
                $key,
                'curl --connect-timeout 2 --max-time 5 -fsS http://10.6.0.2/api/ca/root >/dev/null && curl --connect-timeout 2 --max-time 5 -fsSk https://10.6.0.2/api/me >/dev/null',
                timeoutSeconds: 15,
            );

            $lastException = null;
        } catch (Throwable $exception) {
            $lastException = $exception;
        }

        if ($last?->successful()) {
            return;
        }

        sleep(2);
    }

    expect($last)->not->toBeNull();
    expect($last?->successful())->toBeTrue(($last?->output() ?? '').($last?->errorOutput() ?? '').($lastException?->getMessage() ?? ''));
}

/**
 * @return array<string, mixed>
 */
function nodeNewProductionAppE2EGatewayNode(E2EInstance $gateway, string $name): array
{
    $nameValue = var_export($name, true);

    $php = <<<PHP
echo json_encode(\\App\\Models\\Node::query()->where('name', {$nameValue})->firstOrFail()->only([
    'name',
    'role',
    'environment',
    'tld',
    'host',
    'wireguard_address',
    'gateway_endpoint',
    'ssh_user',
    'user',
    'is_local',
]), JSON_THROW_ON_ERROR);
PHP;

    $result = nodeNewProductionAppE2EOrbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($php),
        "Could not read gateway node {$name}",
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

function nodeNewProductionAppE2EExec(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
{
    $result = $instance->exec($command, $timeoutSeconds);

    expect($result->successful())->toBeTrue($message."\n".$result->output().$result->errorOutput());

    return $result;
}

function nodeNewProductionAppE2EOrbit(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
{
    return nodeNewProductionAppE2EExec(
        $instance,
        'sudo -iu orbit bash -lc '.escapeshellarg($command),
        $message,
        $timeoutSeconds,
    );
}

function nodeNewProductionAppE2ESsh(E2EInstance $instance, string $user, SshKeyPair $key, string $command, ?int $timeoutSeconds = null): ProcessResult
{
    $result = $instance->ssh($user, $key, $command, $timeoutSeconds);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

    return $result;
}

function nodeNewProductionAppE2ETlsServerScript(): string
{
    return <<<'PHP'
<?php

function respond($connection, int $status, string $body, string $contentType = 'application/json'): void
{
    $reason = $status === 200 ? 'OK' : ($status === 422 ? 'Unprocessable Content' : 'Not Found');

    fwrite($connection, "HTTP/1.1 {$status} {$reason}\r\nContent-Type: {$contentType}\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
}

function read_request_body($connection, array $headers): string
{
    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';

    while (strlen($body) < $length && ! feof($connection)) {
        $chunk = fread($connection, $length - strlen($body));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    return $body;
}

function run_node_new(array $input): array
{
    $parts = [
        'cd /home/orbit/orbit && php artisan node:new',
        escapeshellarg((string) ($input['name'] ?? '')),
        '--role='.escapeshellarg((string) ($input['role'] ?? '')),
        '--host='.escapeshellarg((string) ($input['host'] ?? '')),
        '--environment='.escapeshellarg((string) ($input['environment'] ?? '')),
        '--ssh-user='.escapeshellarg((string) ($input['ssh_user'] ?? 'root')),
        '--json',
    ];

    if (($input['tld'] ?? null) !== null && $input['tld'] !== '') {
        $parts[] = '--tld='.escapeshellarg((string) $input['tld']);
    }

    $output = [];
    $exitCode = 0;
    exec(implode(' ', $parts).' 2>&1', $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

$identityPayload = json_encode([
    'success' => [
        'data' => [
            'self' => [
                'name' => 'control-1',
                'role' => 'control',
                'status' => 'active',
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => '10.6.0.3',
                ],
            ],
            'gateway' => [
                'name' => 'gateway',
                'role' => 'gateway',
                'status' => 'active',
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => '10.6.0.2',
                ],
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES);

$context = stream_context_create([
    'ssl' => [
        'local_cert' => '/home/orbit/orbit/storage/app/orbit/certs/10.6.0.2.crt',
        'local_pk' => '/home/orbit/orbit/storage/app/orbit/certs/10.6.0.2.key',
        'allow_self_signed' => true,
        'verify_peer' => false,
    ],
]);

$server = stream_socket_server('tls://10.6.0.2:443', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    fwrite(STDERR, "Could not start TLS server: {$errstr}\n");
    exit(1);
}

while ($connection = @stream_socket_accept($server, -1)) {
    $requestLine = fgets($connection) ?: '';
    $headers = [];

    while (($line = fgets($connection)) !== false) {
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            break;
        }

        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    if (str_starts_with($requestLine, 'GET /api/me ')) {
        respond($connection, 200, $identityPayload);
        fclose($connection);

        continue;
    }

    if (str_starts_with($requestLine, 'POST /api/nodes ')) {
        $input = json_decode(read_request_body($connection, $headers), true);

        if (! is_array($input)) {
            respond($connection, 422, json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid JSON request.',
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR));
            fclose($connection);

            continue;
        }

        [$exitCode, $output] = run_node_new($input);
        respond($connection, $exitCode === 0 ? 200 : 422, $output);
        fclose($connection);

        continue;
    }

    respond($connection, 404, '');
    fclose($connection);
}
PHP;
}
