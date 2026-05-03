<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2EGatewayApi
{
    public static function seedControlIdentity(E2EInstance $gateway, string $controlIp, string $controlUser): void
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

        E2ECommand::orbit(
            $gateway,
            'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($php),
            'Could not seed control identity on gateway',
        );
    }

    public static function installRootSshKey(E2EInstance $gateway, SshKeyPair $key): void
    {
        E2ECommand::exec($gateway, 'install -d -m 700 /root/.ssh', 'Could not prepare root SSH directory on gateway');
        $gateway->copyFileToInstance($key->privateKeyPath, '/root/.ssh/id_ed25519');
        E2ECommand::exec($gateway, 'chmod 600 /root/.ssh/id_ed25519', 'Could not install root SSH key on gateway');
    }

    public static function start(E2EInstance $gateway, string $label): void
    {
        E2ECommand::orbit(
            $gateway,
            'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg("app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf('10.6.0.2'); echo 'issued';"),
            'Could not issue gateway leaf certificate',
        );

        $scriptPath = "/tmp/orbit-{$label}-tls.php";

        E2ECommand::exec(
            $gateway,
            "cat > {$scriptPath} <<'PHP'\n".self::tlsServerScript()."\nPHP",
            'Could not write gateway TLS test server',
        );

        E2ECommand::exec(
            $gateway,
            'cd /home/orbit/orbit && nohup php artisan serve --host=10.6.0.2 --port=80 > /tmp/orbit-gateway-http.log 2>&1 &',
            'Could not start gateway HTTP API',
        );

        E2ECommand::exec(
            $gateway,
            "nohup php {$scriptPath} > /tmp/orbit-gateway-tls.log 2>&1 &",
            'Could not start gateway TLS test server',
        );
    }

    public static function waitForGatewayApi(E2EInstance $control, string $controlUser, SshKeyPair $key): void
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
            } catch (\Throwable $exception) {
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
    public static function getNode(E2EInstance $gateway, string $name): array
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

        $result = E2ECommand::orbit(
            $gateway,
            'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($php),
            "Could not read gateway node {$name}",
        );

        return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private static function tlsServerScript(): string
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

    if (str_starts_with($requestLine, 'GET /api/nodes ') || str_starts_with($requestLine, 'GET /api/nodes?')) {
        $output = [];
        $exitCode = 0;
        exec('cd /home/orbit/orbit && php artisan node:list --json 2>&1', $output, $exitCode);
        respond($connection, $exitCode === 0 ? 200 : 422, implode("\n", $output));
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
}
