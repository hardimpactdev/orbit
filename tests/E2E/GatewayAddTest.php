<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2ERun;
use Tests\E2E\Support\IncusProvider;
use Tests\E2E\Support\ProviderPool;
use Tests\E2E\Support\SshKeyPair;

it('joins a prepared gateway from a ready control VM', function (): void {
    $config = E2EConfig::fromEnvironment();
    $selection = (new ProviderPool([new IncusProvider($config)]))->select(E2EImage::Control, E2EImage::Gateway);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $provider = $selection->provider();
    $run = E2ERun::start($provider, 'gateway-add');

    try {
        $key = $run->createSshKeyPair();
        $control = $run->launchControl('control');
        $gateway = $run->launchGateway('gateway');

        $control->authorizeSsh($config->controlUser, $key);
        $gateway->authorizeSsh('orbit', $key);

        $control->waitForSsh($config->controlUser, $key);
        $gateway->waitForSsh('orbit', $key);

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();

        expect($controlIp)->not->toBe($gatewayIp);

        gatewayAddE2EAssignWireGuardIp($control, '10.6.0.3');
        gatewayAddE2EAssignWireGuardIp($gateway, '10.6.0.2');
        gatewayAddE2ERouteWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
        gatewayAddE2ERouteWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');
        gatewayAddE2ESeedControlIdentity($gateway, $controlIp, $config->controlUser);
        gatewayAddE2EStartGatewayApi($gateway);
        gatewayAddE2EWaitForGatewayApi($control, $config->controlUser, $key);

        $gatewayAdd = gatewayAddE2ESsh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit gateway:add 10.6.0.2 --json",
            timeoutSeconds: 600,
        );

        $payload = json_decode(trim($gatewayAdd->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['result']['action'])->toBe('added')
            ->and($payload['success']['data']['gateway']['name'])->toBe('gateway')
            ->and($payload['success']['data']['gateway']['addresses']['wireguard'])->toBe('10.6.0.2')
            ->and($payload['success']['data']['local_node']['name'])->toBe('control-1')
            ->and($payload['success']['data']['local_node']['addresses']['wireguard'])->toBe('10.6.0.3')
            ->and($payload['success']['data']['local_onboarding']['gateway_trust'])->toBe('trusted')
            ->and($payload['success']['data']['local_onboarding']['gateway_config'])->toBe('stored')
            ->and($payload['success']['data']['local_onboarding']['gateway_api'])->toBe('verified');

        $settings = gatewayAddE2EControlSettings($control, $config->controlUser, $key);

        expect($settings['gateway_url'])->toBe('https://10.6.0.2')
            ->and($settings['gateway_wg_ip'])->toBe('10.6.0.2')
            ->and($settings['ca_sha256'])->toBeString()->not->toBeEmpty()
            ->and($settings['ca_pem_path'])->toContain('storage/app/orbit/gateway-ca/orbit.crt');

        $trustStoreFile = $control->ssh(
            $config->controlUser,
            $key,
            'test -f /usr/local/share/ca-certificates/orbit-gateway-ca-orbit.crt',
        );

        expect($trustStoreFile->successful())->toBeTrue($trustStoreFile->output().$trustStoreFile->errorOutput());

        $localNodeMirrorCount = gatewayAddE2ESsh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && php artisan tinker --execute='echo \\App\\Models\\Node::query()->count();'",
        );

        expect(trim($localNodeMirrorCount->output()))->toBe('0');
    } finally {
        $run->cleanup();
    }
});

function gatewayAddE2EAssignWireGuardIp(E2EInstance $instance, string $wireGuardIp): void
{
    gatewayAddE2EExec(
        $instance,
        sprintf(
            'iface="$(ip -o -4 route show default | awk \'{print $5; exit}\')"; test -n "$iface"; ip addr add %s dev "$iface" 2>/dev/null || true; ip link set "$iface" up',
            escapeshellarg("{$wireGuardIp}/16"),
        ),
        "Could not assign {$wireGuardIp} to {$instance->name()}",
    );
}

function gatewayAddE2ERouteWireGuardPeer(E2EInstance $instance, string $wireGuardIp, string $providerIp, string $sourceWireGuardIp): void
{
    gatewayAddE2EExec(
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

function gatewayAddE2ESeedControlIdentity(E2EInstance $gateway, string $controlIp, string $controlUser): void
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

    gatewayAddE2EOrbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($php),
        'Could not seed control identity on gateway',
    );
}

function gatewayAddE2EStartGatewayApi(E2EInstance $gateway): void
{
    gatewayAddE2EOrbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg("app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf('10.6.0.2'); echo 'issued';"),
        'Could not issue gateway leaf certificate',
    );

    gatewayAddE2EExec(
        $gateway,
        "cat > /tmp/orbit-gateway-add-tls.php <<'PHP'\n".gatewayAddE2ETlsServerScript()."\nPHP",
        'Could not write gateway TLS test server',
    );

    gatewayAddE2EExec(
        $gateway,
        'cd /home/orbit/orbit && nohup php artisan serve --host=10.6.0.2 --port=80 > /tmp/orbit-gateway-http.log 2>&1 &',
        'Could not start gateway HTTP API',
    );

    gatewayAddE2EExec(
        $gateway,
        'nohup php /tmp/orbit-gateway-add-tls.php > /tmp/orbit-gateway-tls.log 2>&1 &',
        'Could not start gateway TLS test server',
    );
}

function gatewayAddE2EWaitForGatewayApi(E2EInstance $control, string $controlUser, SshKeyPair $key): void
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
function gatewayAddE2EControlSettings(E2EInstance $control, string $controlUser, SshKeyPair $key): array
{
    $php = <<<'PHP'
$settings = \App\Models\LocalGatewaySettings::current();
echo json_encode([
    'gateway_url' => $settings->gateway_url,
    'gateway_wg_ip' => $settings->gateway_wg_ip,
    'ca_sha256' => $settings->ca_sha256,
    'ca_pem_path' => $settings->ca_pem_path,
], JSON_THROW_ON_ERROR);
PHP;

    $result = gatewayAddE2ESsh(
        $control,
        $controlUser,
        $key,
        'cd /home/'.$controlUser.'/orbit && php artisan tinker --execute='.escapeshellarg($php),
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

function gatewayAddE2EExec(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
{
    $result = $instance->exec($command, $timeoutSeconds);

    expect($result->successful())->toBeTrue($message."\n".$result->output().$result->errorOutput());

    return $result;
}

function gatewayAddE2EOrbit(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
{
    return gatewayAddE2EExec(
        $instance,
        'sudo -iu orbit bash -lc '.escapeshellarg($command),
        $message,
        $timeoutSeconds,
    );
}

function gatewayAddE2ESsh(E2EInstance $instance, string $user, SshKeyPair $key, string $command, ?int $timeoutSeconds = null): ProcessResult
{
    $result = $instance->ssh($user, $key, $command, $timeoutSeconds);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

    return $result;
}

function gatewayAddE2ETlsServerScript(): string
{
    return <<<'PHP'
<?php

$payload = json_encode([
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

    while (($line = fgets($connection)) !== false) {
        if (rtrim($line, "\r\n") === '') {
            break;
        }
    }

    if (! str_starts_with($requestLine, 'GET /api/me ')) {
        fwrite($connection, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($connection);

        continue;
    }

    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\nConnection: close\r\n\r\n{$payload}");
    fclose($connection);
}
PHP;
}
