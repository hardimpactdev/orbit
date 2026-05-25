<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EGatewayApi
{
    public static function seedOperatorIdentity(
        E2EInstance $gateway,
        string $operatorIp,
        string $operatorUser,
        string $gatewayIp = '10.6.0.2',
        string $operatorWireGuardIp = '10.6.0.3',
    ): void {
        self::seedControlIdentity($gateway, $operatorIp, $operatorUser, $gatewayIp, $operatorWireGuardIp);
    }

    #[\Deprecated(message: 'Migration alias. Use seedOperatorIdentity() for product-facing terminology.')]
    public static function seedControlIdentity(
        E2EInstance $gateway,
        string $controlIp,
        string $controlUser,
        string $gatewayIp = '10.6.0.2',
        string $controlWireGuardIp = '10.6.0.3',
    ): void {
        $controlIpValue = var_export($controlIp, true);
        $controlUserValue = var_export($controlUser, true);
        $gatewayIpValue = var_export($gatewayIp, true);
        $controlWireGuardIpValue = var_export($controlWireGuardIp, true);
        $orbitPathValue = var_export("/home/{$controlUser}/orbit", true);

        $php = <<<PHP
\\App\\Models\\Node::query()->updateOrCreate(
    ['name' => 'control-1'],
    array_merge(
        [
            'role' => 'control',
            'environment' => null,
            'tld' => null,
            'platform' => 'unknown',
            'host' => {$controlIpValue},
            'wireguard_address' => {$controlWireGuardIpValue},
            'gateway_endpoint' => {$gatewayIpValue},
            'user' => {$controlUserValue},
            'orbit_path' => {$orbitPathValue},
            'status' => 'active',
        ],
        \\Illuminate\\Support\\Facades\\Schema::hasColumn('nodes', 'ssh_user') ? ['ssh_user' => {$controlUserValue}] : [],
    ),
);
PHP;

        E2ECommand::orbit(
            $gateway,
            'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($php),
            'Could not seed control identity on gateway',
        );
    }

    public static function installRootSshKey(E2EInstance $gateway, SshKeyPair $key): void
    {
        self::installProvisioningSshKey($gateway, $key);
    }

    public static function installProvisioningSshKey(E2EInstance $gateway, SshKeyPair $key): void
    {
        self::installSshKey($gateway, $key, 'root', '/root');
        self::installSshKeyIfUserExists($gateway, $key, 'orbit', '/home/orbit');
        self::installSshKeyIfUserExists($gateway, $key, 'www-data', '/var/www');
    }

    private static function installSshKeyIfUserExists(E2EInstance $gateway, SshKeyPair $key, string $user, string $home): void
    {
        if (! $gateway->exec('id -u '.escapeshellarg($user).' >/dev/null 2>&1')->successful()) {
            return;
        }

        self::installSshKey($gateway, $key, $user, $home);
    }

    private static function installSshKey(E2EInstance $gateway, SshKeyPair $key, string $user, string $home): void
    {
        $sshDirectory = "{$home}/.ssh";
        $privateKey = "{$sshDirectory}/id_ed25519";

        E2ECommand::exec(
            $gateway,
            sprintf(
                'install -d -m 700 -o %s -g %s %s',
                escapeshellarg($user),
                escapeshellarg($user),
                escapeshellarg($sshDirectory),
            ),
            "Could not prepare {$user} SSH directory on gateway",
        );

        $gateway->copyFileToInstance($key->privateKeyPath, $privateKey);

        E2ECommand::exec(
            $gateway,
            sprintf(
                'chown %s:%s %s && chmod 600 %s',
                escapeshellarg($user),
                escapeshellarg($user),
                escapeshellarg($privateKey),
                escapeshellarg($privateKey),
            ),
            "Could not install {$user} SSH key on gateway",
        );
    }

    /**
     * @param  array<string, string>  $peerIdentityMap
     */
    public static function restart(
        E2EInstance $gateway,
        string $label,
        string $orbitPath = '/home/orbit/orbit',
        string $gatewayIp = '10.6.0.2',
        ?string $wireguardIdentity = null,
        ?string $bindAddress = null,
        ?string $certKey = null,
        array $certSans = [],
        array $peerIdentityMap = [],
    ): void {
        self::stop($gateway);
        self::start($gateway, $label, $orbitPath, $gatewayIp, $wireguardIdentity, $bindAddress, $certKey, $certSans, $peerIdentityMap);
    }

    /**
     * @param  array<string, string>  $peerIdentityMap
     */
    public static function start(
        E2EInstance $gateway,
        string $label,
        string $orbitPath = '/home/orbit/orbit',
        string $gatewayIp = '10.6.0.2',
        ?string $wireguardIdentity = null,
        ?string $bindAddress = null,
        ?string $certKey = null,
        array $certSans = [],
        array $peerIdentityMap = [],
    ): void {
        $wireguardIdentity ??= $gatewayIp;
        $bindAddress ??= $gatewayIp;
        $certKey ??= $gatewayIp;

        if (self::isDockerTopology($gateway)) {
            self::startDocker($gateway, $label, $orbitPath, $wireguardIdentity, $bindAddress, $certKey, $certSans, $peerIdentityMap);

            return;
        }

        $orbitPathArgument = escapeshellarg($orbitPath);
        $certKeyValue = var_export($certKey, true);
        $certSansValue = var_export(array_values($certSans), true);
        $viewCompiledPath = escapeshellarg("{$orbitPath}/storage/framework/views");
        $dockerTopologyModeEnv = self::dockerTopologyModeEnvCommand();

        E2ECommand::orbit(
            $gateway,
            "cd {$orbitPathArgument} && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs && ([ -f .env ] || cp .env.example .env) && grep -Ev '^(ORBIT_IS_GATEWAY|ORBIT_E2E_TRUST_WIREGUARD_HEADER|VIEW_COMPILED_PATH|ORBIT_E2E_DOCKER_TOPOLOGY_MODE)=' .env > .env.tmp && mv .env.tmp .env && printf '\\nORBIT_IS_GATEWAY=true\\nORBIT_E2E_TRUST_WIREGUARD_HEADER=true\\nVIEW_COMPILED_PATH=%s\\n' {$viewCompiledPath} >> .env && {$dockerTopologyModeEnv} && ".self::appKeyCommand().' && orbit tinker --execute='.escapeshellarg("app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf({$certKeyValue}, {$certSansValue}); echo 'issued';"),
            'Could not issue gateway leaf certificate',
        );

        $scriptPath = "/tmp/orbit-{$label}-tls.php";
        $httpRouterPath = "/tmp/orbit-{$label}-http-router.php";

        E2ECommand::exec(
            $gateway,
            "cat > {$scriptPath} <<'PHP'\n".self::tlsServerScript($orbitPath, $wireguardIdentity, $bindAddress, $certKey, $peerIdentityMap)."\nPHP",
            'Could not write gateway TLS test server',
        );

        E2ECommand::exec(
            $gateway,
            "cat > {$httpRouterPath} <<'PHP'\n".self::httpRouterScript($orbitPath)."\nPHP",
            'Could not write gateway HTTP test router',
        );

        E2ECommand::exec(
            $gateway,
            'docker stop orbit-caddy >/dev/null 2>&1 || true',
            'Could not stop gateway orbit-caddy before starting gateway test servers',
        );

        self::prepareRootRemoteShellIdentity($gateway);

        E2ECommand::exec(
            $gateway,
            "cd {$orbitPathArgument} && nohup env VIEW_COMPILED_PATH={$viewCompiledPath} php -d display_errors=0 -S {$bindAddress}:80 -t public {$httpRouterPath} > /tmp/orbit-gateway-http.log 2>&1 &",
            'Could not start gateway HTTP API',
        );

        E2ECommand::exec(
            $gateway,
            "nohup php {$scriptPath} > /tmp/orbit-gateway-tls.log 2>&1 &",
            'Could not start gateway TLS test server',
        );
    }

    public static function stop(E2EInstance $gateway): void
    {
        if (self::isDockerTopology($gateway)) {
            E2ECommand::exec(
                $gateway,
                sprintf(
                    'sudo docker exec %s sh -lc %s >/dev/null 2>&1 || true',
                    escapeshellarg(self::runtimeContainerName($gateway)),
                    escapeshellarg(self::stopServerShellScript()),
                ),
                'Could not stop gateway test servers',
            );

            return;
        }

        E2ECommand::exec(
            $gateway,
            'sh -lc '.escapeshellarg(self::stopServerShellScript()),
            'Could not stop gateway test servers',
        );
    }

    /**
     * @param  array<string, string>  $peerIdentityMap
     */
    private static function startDocker(
        E2EInstance $gateway,
        string $label,
        string $orbitPath,
        string $wireguardIdentity,
        string $bindAddress,
        string $certKey,
        array $certSans,
        array $peerIdentityMap,
    ): void {
        $orbitPathArgument = escapeshellarg($orbitPath);
        $certKeyValue = var_export($certKey, true);
        $certSansValue = var_export(array_values($certSans), true);
        $viewCompiledPath = escapeshellarg("{$orbitPath}/storage/framework/views");
        $dockerTopologyModeEnv = self::dockerTopologyModeEnvCommand();
        $runtimeContainer = escapeshellarg(self::runtimeContainerName($gateway));
        $scriptPath = "/tmp/orbit-{$label}-tls.php";
        $httpRouterPath = "/tmp/orbit-{$label}-http-router.php";
        $scriptPathArgument = escapeshellarg($scriptPath);
        $httpRouterPathArgument = escapeshellarg($httpRouterPath);

        $certificateCommand = "mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs && ([ -f .env ] || cp .env.example .env) && grep -Ev '^(ORBIT_IS_GATEWAY|ORBIT_E2E_TRUST_WIREGUARD_HEADER|VIEW_COMPILED_PATH|ORBIT_E2E_DOCKER_TOPOLOGY_MODE)=' .env > .env.tmp && mv .env.tmp .env && printf '\\nORBIT_IS_GATEWAY=true\\nORBIT_E2E_TRUST_WIREGUARD_HEADER=true\\nVIEW_COMPILED_PATH=%s\\n' {$viewCompiledPath} >> .env && {$dockerTopologyModeEnv} && ".self::appKeyCommand().' && orbit tinker --execute='.escapeshellarg("app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf({$certKeyValue}, {$certSansValue}); echo 'issued';");

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --env %s --workdir %s %s sh -lc %s',
                escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
                $orbitPathArgument,
                $runtimeContainer,
                escapeshellarg($certificateCommand),
            ),
            'Could not issue gateway leaf certificate',
        );

        self::prepareRootRemoteShellIdentity($gateway);
        self::prepareDockerRuntimeRemoteShellIdentity($gateway);

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --workdir %s %s sh -lc %s',
                $orbitPathArgument,
                $runtimeContainer,
                escapeshellarg("cat > {$scriptPathArgument} <<'PHP'\n".self::tlsServerScript($orbitPath, $wireguardIdentity, $bindAddress, $certKey, $peerIdentityMap, dockerRuntime: true)."\nPHP"),
            ),
            'Could not write gateway TLS test server in runtime container',
        );

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --workdir %s %s sh -lc %s',
                $orbitPathArgument,
                $runtimeContainer,
                escapeshellarg("cat > {$httpRouterPathArgument} <<'PHP'\n".self::httpRouterScript($orbitPath)."\nPHP"),
            ),
            'Could not write gateway HTTP test router in runtime container',
        );

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --detach --env %s --env %s --workdir %s %s php -d display_errors=0 -S %s:80 -t public %s',
                escapeshellarg("VIEW_COMPILED_PATH={$orbitPath}/storage/framework/views"),
                escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
                $orbitPathArgument,
                $runtimeContainer,
                escapeshellarg($bindAddress),
                $httpRouterPathArgument,
            ),
            'Could not start gateway HTTP API',
        );

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --detach --env %s --workdir %s %s orbit tinker --execute=%s',
                escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
                $orbitPathArgument,
                $runtimeContainer,
                escapeshellarg('include '.var_export($scriptPath, true).';'),
            ),
            'Could not start gateway TLS test server',
        );
    }

    public static function waitForGatewayApi(E2EInstance $control, string $controlUser, SshKeyPair $key, string $gatewayIp = '10.6.0.2'): void
    {
        $deadline = time() + 120;
        $last = null;
        $lastException = null;

        while (time() < $deadline) {
            try {
                $last = $control->ssh(
                    $controlUser,
                    $key,
                    "curl --connect-timeout 2 --max-time 5 -fsS http://{$gatewayIp}/api/ca/root >/dev/null && curl --connect-timeout 2 --max-time 5 -fsSk https://{$gatewayIp}/api/me >/dev/null",
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

        $message = trim(($last?->output() ?? '').($last?->errorOutput() ?? '').($lastException?->getMessage() ?? ''));

        throw new \RuntimeException($message === '' ? 'Gateway API did not become reachable.' : $message);
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
        'user',
    ]), JSON_THROW_ON_ERROR);
PHP;

        $result = E2ECommand::orbit(
            $gateway,
            'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($php),
            "Could not read gateway node {$name}",
        );

        return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private static function prepareRootRemoteShellIdentity(E2EInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            self::prepareRootRemoteShellIdentityScript(),
            'Could not prepare root RemoteShell identity for gateway HTTP API',
            timeoutSeconds: 60,
        );
    }

    private static function prepareDockerRuntimeRemoteShellIdentity(E2EInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec %s sh -lc %s',
                escapeshellarg(self::runtimeContainerName($gateway)),
                escapeshellarg(self::prepareRootRemoteShellIdentityScript()),
            ),
            'Could not prepare runtime RemoteShell identity for gateway HTTP API',
            timeoutSeconds: 60,
        );
    }

    private static function prepareRootRemoteShellIdentityScript(): string
    {
        return 'if [ -f /home/orbit/.ssh/id_ed25519 ]; then install -d -m 700 /root/.ssh && cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /home/orbit/.ssh/id_ed25519.pub ]; then cp /home/orbit/.ssh/id_ed25519.pub /root/.ssh/id_ed25519.pub; fi; fi';
    }

    private static function httpRouterScript(string $orbitPath): string
    {
        return "<?php\n\n\$orbitPath = ".var_export($orbitPath, true).";\n\n".<<<'PHP_WRAP'
        $publicPath = $orbitPath.'/public';
        $uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '');

        if ($uri !== '/' && is_file($publicPath.$uri)) {
            return false;
        }

        require $publicPath.'/index.php';
        PHP_WRAP;
    }

    /**
     * @param  array<string, string>  $peerIdentityMap
     */
    private static function tlsServerScript(string $orbitPath, string $wireguardIdentity, string $bindAddress, string $certKey, array $peerIdentityMap = [], bool $dockerRuntime = false): string
    {
        $httpUpstream = self::httpUpstreamForBindAddress($bindAddress);
        $runOrbitCommand = $dockerRuntime
            ? "exec(\$script.' 2>&1', \$output, \$exitCode);"
            : "exec('sudo -iu orbit bash -lc '.escapeshellarg(\$script).' 2>&1', \$output, \$exitCode);";
        $streamOrbitCommand = $dockerRuntime
            ? "\$process = popen(\$script.' 2>&1', 'r');"
            : "\$process = popen('sudo -iu orbit bash -lc '.escapeshellarg(\$script).' 2>&1', 'r');";

        $script = "<?php\n\n\$orbitPath = ".var_export($orbitPath, true).";\n\$wireguardIdentity = ".var_export($wireguardIdentity, true).";\n\$bindAddress = ".var_export($bindAddress, true).";\n\$certKey = ".var_export($certKey, true).";\n\$httpUpstream = ".var_export($httpUpstream, true).";\n\$peerIdentityMap = ".var_export($peerIdentityMap, true).";\n\n".<<<'PHP_WRAP'
        
        function respond($connection, int $status, string $body, string $contentType = 'application/json'): void
        {
            $reason = match ($status) {
                200 => 'OK',
                201 => 'Created',
                400 => 'Bad Request',
                403 => 'Forbidden',
                422 => 'Unprocessable Content',
                502 => 'Bad Gateway',
                default => 'Not Found',
            };
        
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

        function peer_ip($connection): ?string
        {
            $peer = stream_socket_get_name($connection, true);

            if (! is_string($peer) || $peer === '') {
                return null;
            }

            $host = preg_replace('/:\d+$/', '', $peer);

            if (! is_string($host)) {
                return null;
            }

            $host = normalize_peer_ip($host);

            return filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
        }

        function normalize_peer_ip(string $peerIp): string
        {
            $peerIp = trim($peerIp, '[]');

            return str_starts_with(strtolower($peerIp), '::ffff:')
                ? substr($peerIp, 7)
                : $peerIp;
        }

        function canonical_peer_ip(string $peerIp): string
        {
            global $peerIdentityMap;

            $peerIp = normalize_peer_ip($peerIp);

            if (is_string($peerIdentityMap[$peerIp] ?? null)) {
                return $peerIdentityMap[$peerIp];
            }

            if (preg_match('/^10\.\d+\.0\.(?<host>\d+)$/', $peerIp, $matches) === 1) {
                return match ((int) $matches['host']) {
                    2 => '10.6.0.2',
                    3 => '10.6.0.3',
                    4 => '10.6.0.4',
                    5 => '10.6.0.5',
                    6 => '10.6.0.6',
                    7 => '10.6.0.7',
                    8 => '10.6.0.8',
                    9 => '10.6.0.9',
                    default => $peerIp,
                };
            }

            return $peerIp;
        }

        function http_upstream(): string
        {
            global $httpUpstream;

            $upstream = is_string($httpUpstream) ? trim($httpUpstream) : '';

            return $upstream === '' || $upstream === '0.0.0.0' ? '127.0.0.1' : $upstream;
        }

        function proxy_to_laravel($connection, string $requestLine, array $headers, string $body): void
        {
            global $wireguardIdentity;

            $parts = explode(' ', trim($requestLine), 3);

            if (count($parts) < 3) {
                respond($connection, 400, '');

                return;
            }

            $upstream = @stream_socket_client('tcp://'.http_upstream().':80', $errno, $errstr, 5);

            if ($upstream === false) {
                respond($connection, 502, json_encode([
                    'error' => [
                        'code' => 'gateway_unavailable',
                        'message' => "Could not proxy request to Laravel HTTP server: {$errstr}",
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR));

                return;
            }

            stream_set_timeout($upstream, 900);

            $headers['host'] = $wireguardIdentity;
            $headers['connection'] = 'close';
            $headers['accept-encoding'] = 'identity';
            $headers['content-length'] = (string) strlen($body);

            $clientIp = peer_ip($connection);

            if ($clientIp !== null) {
                $identity = canonical_peer_ip($clientIp);
                $headers['x-orbit-e2e-wireguard-ip'] = $identity;
            }

            fwrite($upstream, "{$parts[0]} {$parts[1]} {$parts[2]}\r\n");

            foreach ($headers as $name => $value) {
                if (! is_string($value)) {
                    continue;
                }

                fwrite($upstream, "{$name}: {$value}\r\n");
            }

            fwrite($upstream, "\r\n{$body}");

            while (! feof($upstream)) {
                $chunk = fread($upstream, 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                fwrite($connection, $chunk);
            }

            fclose($upstream);
        }
        
        function run_orbit_command(string $command): array
        {
            global $orbitPath;
        
            $output = [];
            $exitCode = 0;
            $script = 'cd '.escapeshellarg($orbitPath).' && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs && VIEW_COMPILED_PATH='.escapeshellarg($orbitPath.'/storage/framework/views').' '.$command;
            __RUN_ORBIT_COMMAND__
        
            return [$exitCode, implode("\n", $output)];
        }

        function stream_orbit_command($connection, string $command): void
        {
            global $orbitPath;

            $script = 'cd '.escapeshellarg($orbitPath).' && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs && VIEW_COMPILED_PATH='.escapeshellarg($orbitPath.'/storage/framework/views').' '.$command;
            __STREAM_ORBIT_COMMAND__

            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=UTF-8\r\nConnection: close\r\n\r\n");

            if ($process === false) {
                fwrite($connection, "Could not open gateway stream.\n");

                return;
            }

            while (! feof($process)) {
                $chunk = fread($process, 8192);

                if ($chunk === false || $chunk === '') {
                    usleep(50_000);

                    continue;
                }

                fwrite($connection, $chunk);
            }

            pclose($process);
        }

        function stream_tool_logs($connection, string $tool, array $query): void
        {
            $parts = [
                'timeout 6s orbit tool:logs',
                escapeshellarg($tool),
                '--follow',
                '--lines='.escapeshellarg((string) max(1, (int) ($query['lines'] ?? 100))),
            ];

            foreach (['node', 'app'] as $option) {
                $value = $query[$option] ?? null;

                if (is_string($value) && $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg($value);
                }
            }

            stream_orbit_command($connection, implode(' ', $parts).' || true');
        }
        
        function run_node_new(array $input): array
        {
            $parts = [
                'orbit node:new',
                escapeshellarg((string) ($input['name'] ?? '')),
                '--role='.escapeshellarg((string) ($input['role'] ?? '')),
                '--host='.escapeshellarg((string) ($input['host'] ?? '')),
                '--environment='.escapeshellarg((string) ($input['environment'] ?? '')),
                '--user='.escapeshellarg((string) ($input['user'] ?? 'root')),
                '--json',
            ];
        
            if (($input['tld'] ?? null) !== null && $input['tld'] !== '') {
                $parts[] = '--tld='.escapeshellarg((string) $input['tld']);
            }
        
            return run_orbit_command(implode(' ', $parts));
        }
        
        function run_node_show(string $name): array
        {
            return run_orbit_command('orbit node:show '.escapeshellarg($name).' --json');
        }

        function run_node_agent_ide(string $name, array $input): array
        {
            return run_orbit_command(
                'orbit node:agent-ide '
                    .escapeshellarg($name).' '
                    .escapeshellarg((string) ($input['agent_ide'] ?? '')).' --json'
            );
        }

        function run_node_update(string $name, array $input): array
        {
            $parts = [
                'orbit node:update',
                escapeshellarg($name),
                '--json',
            ];

            foreach ([
                'host' => 'host',
                'environment' => 'environment',
                'public_ipv4' => 'public-ipv4',
                'public_ipv6' => 'public-ipv6',
            ] as $field => $option) {
                $value = $input[$field] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            return run_orbit_command(implode(' ', $parts));
        }

        function run_node_grant(array $input): array
        {
            $parts = [
                'orbit node:grant',
                escapeshellarg((string) ($input['consuming_node'] ?? '')),
                escapeshellarg((string) ($input['serving_node'] ?? '')),
                '--json',
            ];

            $preset = $input['preset'] ?? null;
            if (is_scalar($preset) && (string) $preset !== '') {
                $parts[] = '--preset='.escapeshellarg((string) $preset);
            }

            $permissions = $input['permissions'] ?? null;
            if (is_scalar($permissions) && (string) $permissions !== '') {
                $parts[] = '--permissions='.escapeshellarg((string) $permissions);
            }

            $force = $input['force'] ?? false;
            if ($force === true || $force === 'true' || $force === '1' || $force === 1) {
                $parts[] = '--force';
            }

            return run_orbit_command(implode(' ', $parts));
        }

        function run_node_revoke(array $input): array
        {
            return run_orbit_command(
                'orbit node:revoke '
                    .escapeshellarg((string) ($input['consuming_node'] ?? '')).' '
                    .escapeshellarg((string) ($input['serving_node'] ?? '')).' --force --json'
            );
        }

        function run_node_remove(string $name): array
        {
            return run_orbit_command('orbit node:remove '.escapeshellarg($name).' --force --json');
        }

        function run_app_show(string $name): array
        {
            return run_orbit_command('orbit app:show '.escapeshellarg($name).' --json');
        }

        function run_app_new(array $input): array
        {
            $parts = [
                'orbit app:new',
                escapeshellarg((string) ($input['name'] ?? '')),
                '--node='.escapeshellarg((string) ($input['node'] ?? '')),
                '--root='.escapeshellarg((string) ($input['root'] ?? 'public')),
                '--php-version='.escapeshellarg((string) ($input['php_version'] ?? '8.5')),
                '--json',
            ];

            foreach (['repository' => 'repo', 'domain' => 'domain'] as $field => $option) {
                $value = $input[$field] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            return run_orbit_command(implode(' ', $parts));
        }

        function run_app_register(array $input): array
        {
            $parts = [
                'orbit app:register',
                escapeshellarg((string) ($input['name'] ?? '')),
                '--root='.escapeshellarg((string) ($input['root'] ?? 'public')),
                '--php-version='.escapeshellarg((string) ($input['php_version'] ?? '8.5')),
                '--json',
            ];

            foreach (['node' => 'node', 'path' => 'path', 'domain' => 'domain'] as $field => $option) {
                $value = $input[$field] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            return run_orbit_command(implode(' ', $parts));
        }

        function run_app_root(string $name, array $input): array
        {
            return run_orbit_command(
                'orbit app:root '
                    .escapeshellarg($name).' '
                    .escapeshellarg((string) ($input['root'] ?? '')).' --json'
            );
        }

        function run_app_agent_ide(string $name, array $input): array
        {
            return run_orbit_command(
                'orbit app:agent-ide '
                    .escapeshellarg($name).' '
                    .escapeshellarg((string) ($input['agent_ide'] ?? '')).' --json'
            );
        }

        function run_app_remove(string $name): array
        {
            return run_orbit_command('orbit app:remove '.escapeshellarg($name).' --force --json');
        }

        function run_activity_list(array $query): array
        {
            $parts = ['orbit activity:list --json'];

            foreach (['app', 'node', 'effect', 'correlation', 'limit'] as $option) {
                $value = $query[$option] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            return run_orbit_command(implode(' ', $parts));
        }

        function run_workspace_history(?string $name, array $query): array
        {
            $parts = ['orbit workspace:history'];

            if ($name !== null && $name !== '') {
                $parts[] = escapeshellarg($name);
            }

            foreach (['app', 'limit', 'since', 'until'] as $option) {
                $value = $query[$option] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            $parts[] = '--json';

            return run_orbit_command(implode(' ', $parts));
        }

        function run_workspace_log(string $run): array
        {
            return run_orbit_command('orbit workspace:log '.escapeshellarg($run).' --json');
        }

        function run_workspace_new(array $input): array
        {
            $parts = [
                'orbit workspace:new',
                escapeshellarg((string) ($input['name'] ?? '')),
            ];

            foreach ([
                'app' => 'app',
                'base' => 'base',
                'php_version' => 'php-version',
            ] as $field => $option) {
                $value = $input[$field] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            $parts[] = '--json';

            return run_orbit_command(implode(' ', $parts));
        }

        function run_workspace_setup(array $input): array
        {
            $parts = ['orbit workspace:setup'];
            $name = $input['name'] ?? null;

            if (is_scalar($name) && (string) $name !== '') {
                $parts[] = escapeshellarg((string) $name);
            }

            foreach (['app' => 'app', 'path' => 'path'] as $field => $option) {
                $value = $input[$field] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            $parts[] = '--json';

            return run_orbit_command(implode(' ', $parts));
        }

        function run_workspace_steps(string $phase, array $query): array
        {
            $parts = ["orbit workspace-{$phase}-step:list"];
            $app = $query['app'] ?? null;
            $path = $query['path'] ?? null;

            if (is_scalar($app) && (string) $app !== '') {
                $parts[] = '--app='.escapeshellarg((string) $app);
            } elseif (is_scalar($path) && (string) $path !== '') {
                chdir((string) $path);
            }

            $parts[] = '--json';
        
            return run_orbit_command(implode(' ', $parts));
        }

        function run_workspace_step_add(string $phase, array $input): array
        {
            $parts = ["orbit workspace-{$phase}-step:add"];

            foreach ([
                'app' => 'app',
                'command' => 'command',
                'timeout' => 'timeout',
                'before' => 'before',
                'after' => 'after',
            ] as $field => $option) {
                $value = $input[$field] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            $parts[] = '--json';
        
            return run_orbit_command(implode(' ', $parts));
        }

        function run_workspace_step_remove(string $phase, string $step, array $query): array
        {
            $parts = [
                "orbit workspace-{$phase}-step:remove",
                '--step='.escapeshellarg($step),
                '--force',
            ];

            foreach (['app' => 'app'] as $field => $option) {
                $value = $query[$field] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $parts[] = "--{$option}=".escapeshellarg((string) $value);
                }
            }

            $parts[] = '--json';
        
            return run_orbit_command(implode(' ', $parts));
        }

        function run_workspace_remove(string $name, array $query, array $input): array
        {
            $parts = [
                'orbit workspace:remove',
                escapeshellarg($name),
                '--force',
            ];

            $app = $query['app'] ?? null;

            if (is_scalar($app) && (string) $app !== '') {
                $parts[] = '--app='.escapeshellarg((string) $app);
            }

            if (($input['keep_files'] ?? false) === true) {
                $parts[] = '--keep-files';
            }

            $parts[] = '--json';

            return run_orbit_command(implode(' ', $parts));
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
                            'wireguard' => preg_replace('/\.2$/', '.3', $wireguardIdentity),
                        ],
                    ],
                    'gateway' => [
                        'name' => 'gateway',
                        'role' => 'gateway',
                        'status' => 'active',
                        'platform' => 'unknown',
                        'addresses' => [
                            'wireguard' => $wireguardIdentity,
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
        
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $orbitPath.'/storage/app/orbit/certs/'.$certKey.'.crt',
                'local_pk' => $orbitPath.'/storage/app/orbit/certs/'.$certKey.'.key',
                'allow_self_signed' => true,
                'verify_peer' => false,
            ],
        ]);
        
        $server = stream_socket_server('tls://'.$bindAddress.':443', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        
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

            if (str_starts_with($requestLine, 'GET /api/activity ') || str_starts_with($requestLine, 'GET /api/activity?')) {
                $path = explode(' ', $requestLine)[1] ?? '/api/activity';
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];

                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }

                [$exitCode, $output] = run_activity_list($query);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^GET /api/tools/([^ ?]+)/logs/stream#', $requestLine, $matches) === 1) {
                $path = explode(' ', $requestLine)[1] ?? '/api/tools/'.$matches[1].'/logs/stream';
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];

                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }

                stream_tool_logs($connection, urldecode($matches[1]), $query);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'GET /api/nodes ') || str_starts_with($requestLine, 'GET /api/nodes?')) {
                $path = explode(' ', $requestLine)[1] ?? '/api/nodes';
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];
        
                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }
        
                $parts = ['orbit node:list --json'];
        
                foreach (['role', 'environment'] as $option) {
                    $value = $query[$option] ?? null;
        
                    if (is_string($value) && $value !== '') {
                        $parts[] = "--{$option}=".escapeshellarg($value);
                    }
                }
        
                [$exitCode, $output] = run_orbit_command(implode(' ', $parts));
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);
        
                continue;
            }
        
            if (preg_match('#^GET /api/nodes/([^ ?]+)#', $requestLine, $matches) === 1) {
                [$exitCode, $output] = run_node_show(urldecode($matches[1]));
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);
        
                continue;
            }

            if (preg_match('#^POST /api/nodes/([^ ?]+)/agent-ide#', $requestLine, $matches) === 1) {
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

                [$exitCode, $output] = run_node_agent_ide(urldecode($matches[1]), $input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'POST /api/nodes/grant ')) {
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

                [$exitCode, $output] = run_node_grant($input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'POST /api/nodes/revoke ')) {
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

                [$exitCode, $output] = run_node_revoke($input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^DELETE /api/nodes/([^ ?]+)#', $requestLine, $matches) === 1) {
                read_request_body($connection, $headers);

                [$exitCode, $output] = run_node_remove(urldecode($matches[1]));
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^PUT /api/nodes/([^ ?]+)#', $requestLine, $matches) === 1) {
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

                [$exitCode, $output] = run_node_update(urldecode($matches[1]), $input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'GET /api/apps ') || str_starts_with($requestLine, 'GET /api/apps?')) {
                $path = explode(' ', $requestLine)[1] ?? '/api/apps';
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];
        
                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }
        
                $parts = ['orbit app:list --json'];
        
                foreach (['node', 'environment'] as $option) {
                    $value = $query[$option] ?? null;
        
                    if (is_string($value) && $value !== '') {
                        $parts[] = "--{$option}=".escapeshellarg($value);
                    }
                }
        
                [$exitCode, $output] = run_orbit_command(implode(' ', $parts));
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);
        
                continue;
            }
        
            if (preg_match('#^GET /api/apps/([^ ?]+)#', $requestLine, $matches) === 1) {
                [$exitCode, $output] = run_app_show(urldecode($matches[1]));
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);
        
                continue;
            }

            if (str_starts_with($requestLine, 'GET /api/workspaces/history/resolve-by-path?')) {
                $path = explode(' ', $requestLine)[1] ?? '/api/workspaces/history/resolve-by-path';
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];

                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }

                [$exitCode, $output] = run_workspace_history(null, $query);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^GET /api/workspaces/runs/([^ ?]+)/log#', $requestLine, $matches) === 1) {
                [$exitCode, $output] = run_workspace_log(urldecode($matches[1]));
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'POST /api/workspaces ')) {
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

                [$exitCode, $output] = run_workspace_new($input);
                respond($connection, $exitCode === 0 ? 201 : 422, $output);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'POST /api/workspaces/setup ')) {
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

                [$exitCode, $output] = run_workspace_setup($input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^GET /api/workspaces/steps/(setup|teardown)#', $requestLine, $matches) === 1) {
                $path = explode(' ', $requestLine)[1] ?? "/api/workspaces/steps/{$matches[1]}";
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];

                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }

                [$exitCode, $output] = run_workspace_steps($matches[1], $query);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^POST /api/workspaces/steps/(setup|teardown)#', $requestLine, $matches) === 1) {
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

                [$exitCode, $output] = run_workspace_step_add($matches[1], $input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^DELETE /api/workspaces/steps/(setup|teardown)/([^ ?]+)#', $requestLine, $matches) === 1) {
                $input = json_decode(read_request_body($connection, $headers), true);

                if (! is_array($input) || ($input['destructive_consent'] ?? false) !== true) {
                    respond($connection, 422, json_encode([
                        'error' => [
                            'code' => 'validation_failed',
                            'message' => 'Use --force to remove this workspace step.',
                            'meta' => ['field' => 'force'],
                        ],
                    ], JSON_THROW_ON_ERROR));
                    fclose($connection);

                    continue;
                }

                $path = explode(' ', $requestLine)[1] ?? "/api/workspaces/steps/{$matches[1]}/{$matches[2]}";
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];

                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }

                [$exitCode, $output] = run_workspace_step_remove($matches[1], urldecode($matches[2]), $query);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^DELETE /api/workspaces/([^ ?]+)#', $requestLine, $matches) === 1) {
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

                if (($input['destructive_consent'] ?? false) !== true) {
                    respond($connection, 422, json_encode([
                        'error' => [
                            'code' => 'validation_failed',
                            'message' => 'Use --force to remove this workspace.',
                            'meta' => ['field' => 'force'],
                        ],
                    ], JSON_THROW_ON_ERROR));
                    fclose($connection);

                    continue;
                }

                $path = explode(' ', $requestLine)[1] ?? "/api/workspaces/{$matches[1]}";
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];

                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }

                [$exitCode, $output] = run_workspace_remove(urldecode($matches[1]), $query, $input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^GET /api/workspaces/([^ ?]+)/history#', $requestLine, $matches) === 1) {
                $path = explode(' ', $requestLine)[1] ?? "/api/workspaces/{$matches[1]}/history";
                $queryString = parse_url($path, PHP_URL_QUERY);
                $query = [];

                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                }

                [$exitCode, $output] = run_workspace_history(urldecode($matches[1]), $query);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^POST /api/apps/([^ ?]+)/agent-ide#', $requestLine, $matches) === 1) {
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

                [$exitCode, $output] = run_app_agent_ide(urldecode($matches[1]), $input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^POST /api/apps/([^ ?]+)/root#', $requestLine, $matches) === 1) {
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

                [$exitCode, $output] = run_app_root(urldecode($matches[1]), $input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (preg_match('#^DELETE /api/apps/([^ ?]+)#', $requestLine, $matches) === 1) {
                read_request_body($connection, $headers);

                [$exitCode, $output] = run_app_remove(urldecode($matches[1]));
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'POST /api/apps/register ')) {
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

                [$exitCode, $output] = run_app_register($input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'POST /api/apps ')) {
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

                [$exitCode, $output] = run_app_new($input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
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
        
            $body = read_request_body($connection, $headers);
            proxy_to_laravel($connection, $requestLine, $headers, $body);
            fclose($connection);
        }
        PHP_WRAP;

        return str_replace(
            ['__RUN_ORBIT_COMMAND__', '__STREAM_ORBIT_COMMAND__'],
            [$runOrbitCommand, $streamOrbitCommand],
            $script,
        );
    }

    private static function httpUpstreamForBindAddress(string $bindAddress): string
    {
        $bindAddress = trim($bindAddress);

        return $bindAddress === '' || $bindAddress === '0.0.0.0'
            ? '127.0.0.1'
            : $bindAddress;
    }

    private static function isDockerTopology(E2EInstance $instance): bool
    {
        return $instance instanceof DockerInstance || $instance instanceof DockerBuildInstance;
    }

    private static function runtimeContainerName(E2EInstance $instance): string
    {
        return "{$instance->name()}-orbit-runtime";
    }

    /**
     * @param  array<string, string>  $peerIdentityMap
     */
    private static function tlsServerTinkerCode(string $orbitPath, string $wireguardIdentity, string $bindAddress, string $certKey, array $peerIdentityMap = [], bool $dockerRuntime = false): string
    {
        $script = self::tlsServerScript($orbitPath, $wireguardIdentity, $bindAddress, $certKey, $peerIdentityMap, $dockerRuntime);

        return preg_replace('/^<\?php\s*/', '', $script) ?? $script;
    }

    private static function dockerTopologyModeEnvCommand(): string
    {
        if (getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE') !== 'dns-alias') {
            return ':';
        }

        return "printf '%s\\n' 'ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias' >> .env";
    }

    private static function appKeyCommand(): string
    {
        return implode(' && ', [
            "(grep -q '^APP_KEY=' .env || printf '%s\\n' 'APP_KEY=' >> .env)",
            "(grep -Eq '^APP_KEY=base64:.+' .env || orbit key:generate --force --no-interaction)",
            "grep -Eq '^APP_KEY=base64:.+' .env",
        ]);
    }

    private static function stopServerShellScript(): string
    {
        return <<<'SH'
set +e
pids=""

for file in /proc/[0-9]*/cmdline; do
    pid="${file#/proc/}"
    pid="${pid%%/*}"

    if [ "$pid" -le 1 ] || [ "$pid" -eq "$$" ]; then
        continue
    fi

    command="$(tr '\000' ' ' < "$file" 2>/dev/null || true)"

    case "$command" in
        */tmp/orbit-*-http-router.php*|*/tmp/orbit-*-tls.php*|*orbit\ serve\ --host=*--port=80*|*php\ *artisan\ serve\ --host=*--port=80*)
            pids="$pids $pid"
            kill -TERM "$pid" >/dev/null 2>&1 || true
            ;;
    esac
done

sleep 0.2

for pid in $pids; do
    kill -KILL "$pid" >/dev/null 2>&1 || true
done
SH;
    }
}
