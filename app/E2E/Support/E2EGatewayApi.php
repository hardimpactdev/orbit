<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EGatewayApi
{
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
    [
        'role' => 'control',
        'environment' => null,
        'tld' => null,
        'platform' => 'unknown',
        'host' => {$controlIpValue},
        'wireguard_address' => {$controlWireGuardIpValue},
        'gateway_endpoint' => {$gatewayIpValue},
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

    public static function restart(E2EInstance $gateway, string $label, string $orbitPath = '/home/orbit/orbit', string $gatewayIp = '10.6.0.2'): void
    {
        self::stop($gateway);
        self::start($gateway, $label, $orbitPath, $gatewayIp);
    }

    public static function start(E2EInstance $gateway, string $label, string $orbitPath = '/home/orbit/orbit', string $gatewayIp = '10.6.0.2'): void
    {
        $orbitPathArgument = escapeshellarg($orbitPath);
        $gatewayIpValue = var_export($gatewayIp, true);
        $viewCompiledPath = escapeshellarg("{$orbitPath}/storage/framework/views");

        E2ECommand::orbit(
            $gateway,
            "cd {$orbitPathArgument} && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs && grep -v '^VIEW_COMPILED_PATH=' .env > .env.tmp && mv .env.tmp .env && printf '\\nVIEW_COMPILED_PATH=%s\\n' {$viewCompiledPath} >> .env && php artisan tinker --execute=".escapeshellarg("app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf({$gatewayIpValue}); echo 'issued';"),
            'Could not issue gateway leaf certificate',
        );

        $scriptPath = "/tmp/orbit-{$label}-tls.php";

        E2ECommand::exec(
            $gateway,
            "cat > {$scriptPath} <<'PHP'\n".self::tlsServerScript($orbitPath, $gatewayIp)."\nPHP",
            'Could not write gateway TLS test server',
        );

        E2ECommand::exec(
            $gateway,
            "cd {$orbitPathArgument} && nohup env VIEW_COMPILED_PATH={$viewCompiledPath} php artisan serve --host={$gatewayIp} --port=80 > /tmp/orbit-gateway-http.log 2>&1 &",
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
        E2ECommand::exec(
            $gateway,
            'php -r '.escapeshellarg(self::stopServerScript()),
            'Could not stop gateway test servers',
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

    private static function tlsServerScript(string $orbitPath, string $gatewayIp): string
    {
        return "<?php\n\n\$orbitPath = ".var_export($orbitPath, true).";\n\$gatewayIp = ".var_export($gatewayIp, true).";\n\n".<<<'PHP_WRAP'
        
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
        
        function run_orbit_command(string $command): array
        {
            global $orbitPath;
        
            $output = [];
            $exitCode = 0;
            $script = 'cd '.escapeshellarg($orbitPath).' && VIEW_COMPILED_PATH='.escapeshellarg($orbitPath.'/storage/framework/views').' '.$command;
            exec('sudo -iu orbit bash -lc '.escapeshellarg($script).' 2>&1', $output, $exitCode);
        
            return [$exitCode, implode("\n", $output)];
        }

        function stream_orbit_command($connection, string $command): void
        {
            global $orbitPath;

            $script = 'cd '.escapeshellarg($orbitPath).' && VIEW_COMPILED_PATH='.escapeshellarg($orbitPath.'/storage/framework/views').' '.$command;
            $process = popen('sudo -iu orbit bash -lc '.escapeshellarg($script).' 2>&1', 'r');

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
                'timeout 6s php artisan tool:logs',
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
                'php artisan node:new',
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
        
            return run_orbit_command(implode(' ', $parts));
        }
        
        function run_node_show(string $name): array
        {
            return run_orbit_command('php artisan node:show '.escapeshellarg($name).' --json');
        }

        function run_node_agent_ide(string $name, array $input): array
        {
            return run_orbit_command(
                'php artisan node:agent-ide '
                    .escapeshellarg($name).' '
                    .escapeshellarg((string) ($input['agent_ide'] ?? '')).' --json'
            );
        }

        function run_node_update(string $name, array $input): array
        {
            $parts = [
                'php artisan node:update',
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
            return run_orbit_command(
                'php artisan node:grant '
                    .escapeshellarg((string) ($input['consuming_node'] ?? '')).' '
                    .escapeshellarg((string) ($input['serving_node'] ?? '')).' --json'
            );
        }

        function run_node_revoke(array $input): array
        {
            return run_orbit_command(
                'php artisan node:revoke '
                    .escapeshellarg((string) ($input['consuming_node'] ?? '')).' '
                    .escapeshellarg((string) ($input['serving_node'] ?? '')).' --force --json'
            );
        }

        function run_node_remove(string $name): array
        {
            return run_orbit_command('php artisan node:remove '.escapeshellarg($name).' --force --json');
        }

        function run_app_show(string $name): array
        {
            return run_orbit_command('php artisan app:show '.escapeshellarg($name).' --json');
        }

        function run_app_new(array $input): array
        {
            $parts = [
                'php artisan app:new',
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
                'php artisan app:register',
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
                'php artisan app:root '
                    .escapeshellarg($name).' '
                    .escapeshellarg((string) ($input['root'] ?? '')).' --json'
            );
        }

        function run_app_agent_ide(string $name, array $input): array
        {
            return run_orbit_command(
                'php artisan app:agent-ide '
                    .escapeshellarg($name).' '
                    .escapeshellarg((string) ($input['agent_ide'] ?? '')).' --json'
            );
        }

        function run_app_remove(string $name): array
        {
            return run_orbit_command('php artisan app:remove '.escapeshellarg($name).' --force --json');
        }

        function run_activity_list(array $query): array
        {
            $parts = ['php artisan activity:list --json'];

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
            $parts = ['php artisan workspace:history'];

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
            return run_orbit_command('php artisan workspace:log '.escapeshellarg($run).' --json');
        }

        function run_workspace_steps(string $phase, array $query): array
        {
            $parts = ["php artisan workspace-{$phase}-step:list"];
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
            $parts = ["php artisan workspace-{$phase}-step:add"];

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
                "php artisan workspace-{$phase}-step:remove",
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
                'php artisan workspace:remove',
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

        function orbit_database(): PDO
        {
            global $orbitPath;

            $database = new PDO('sqlite:'.$orbitPath.'/database/database.sqlite');
            $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $database;
        }

        function current_timestamp(): string
        {
            return gmdate('Y-m-d H:i:s');
        }

        function scheduler_caller(PDO $database): array
        {
            $statement = $database->prepare("select * from nodes where name = 'app-dev-1' limit 1");
            $statement->execute();
            $caller = $statement->fetch(PDO::FETCH_ASSOC);

            if (! is_array($caller)) {
                throw new RuntimeException('app-dev-1 was not found in gateway registry.');
            }

            return $caller;
        }

        function run_schedule_sync(): array
        {
            try {
                $database = orbit_database();
                $caller = scheduler_caller($database);
                $statement = $database->prepare(
                    "select schedules.* from schedules
                    left join apps on schedules.app_id = apps.id
                    where schedules.enabled = 1
                        and schedules.status = 'expected'
                        and (
                            (schedules.scope = 'app' and apps.node_id = :node_id)
                            or (schedules.scope = 'node' and schedules.node_id = :node_id)
                            or (schedules.scope = 'orbit' and :role = 'gateway')
                        )
                    order by schedules.id"
                );
                $statement->execute([
                    'node_id' => $caller['id'],
                    'role' => $caller['role'],
                ]);

                $schedules = array_map(fn (array $schedule): array => [
                    'schedule_key' => $schedule['schedule_key'],
                    'name' => $schedule['name'],
                    'scope' => $schedule['scope'],
                    'target' => [
                        'name' => $schedule['target_name'],
                    ],
                    'interval' => $schedule['interval'],
                    'timezone' => $schedule['timezone'],
                    'execution' => [
                        'type' => $schedule['execution_type'],
                        'value' => $schedule['execution_value'],
                    ],
                    'enabled' => (bool) $schedule['enabled'],
                    'status' => $schedule['status'],
                ], $statement->fetchAll(PDO::FETCH_ASSOC));

                return [0, json_encode([
                    'success' => [
                        'data' => ['schedules' => $schedules],
                        'meta' => [
                            'node' => $caller['name'],
                            'count' => count($schedules),
                        ],
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
            } catch (Throwable $exception) {
                return [1, $exception->getMessage()];
            }
        }

        function run_schedule_heartbeat(array $input): array
        {
            try {
                $database = orbit_database();
                $caller = scheduler_caller($database);
                $now = current_timestamp();
                $heartbeatAt = (string) ($input['heartbeat_at'] ?? $now);
                $registrySyncedAt = $input['registry_synced_at'] ?? null;
                $existing = $database->prepare('select id from scheduler_states where node_id = :node_id limit 1');
                $existing->execute(['node_id' => $caller['id']]);
                $stateId = $existing->fetchColumn();

                if ($stateId === false) {
                    $statement = $database->prepare('insert into scheduler_states (node_id, heartbeat_at, registry_synced_at, created_at, updated_at) values (:node_id, :heartbeat_at, :registry_synced_at, :created_at, :updated_at)');
                    $statement->execute([
                        'node_id' => $caller['id'],
                        'heartbeat_at' => $heartbeatAt,
                        'registry_synced_at' => $registrySyncedAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $statement = $database->prepare('update scheduler_states set heartbeat_at = :heartbeat_at, registry_synced_at = :registry_synced_at, updated_at = :updated_at where id = :id');
                    $statement->execute([
                        'id' => $stateId,
                        'heartbeat_at' => $heartbeatAt,
                        'registry_synced_at' => $registrySyncedAt,
                        'updated_at' => $now,
                    ]);
                }

                return [0, json_encode([
                    'success' => [
                        'data' => [
                            'state' => [
                                'node' => $caller['name'],
                                'heartbeat_at' => $heartbeatAt,
                                'registry_synced_at' => $registrySyncedAt,
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
            } catch (Throwable $exception) {
                return [1, $exception->getMessage()];
            }
        }

        function run_schedule_run_store(array $input): array
        {
            try {
                $database = orbit_database();
                $caller = scheduler_caller($database);
                $now = current_timestamp();
                $statement = $database->prepare('insert into schedule_runs (node_id, schedule_key, status, exit_code, stdout, stderr, started_at, finished_at, created_at, updated_at) values (:node_id, :schedule_key, :status, :exit_code, :stdout, :stderr, :started_at, :finished_at, :created_at, :updated_at)');
                $statement->execute([
                    'node_id' => $caller['id'],
                    'schedule_key' => (string) ($input['schedule_key'] ?? ''),
                    'status' => (string) ($input['status'] ?? 'failed'),
                    'exit_code' => $input['exit_code'] ?? null,
                    'stdout' => (string) ($input['stdout'] ?? ''),
                    'stderr' => (string) ($input['stderr'] ?? ''),
                    'started_at' => (string) ($input['started_at'] ?? $now),
                    'finished_at' => $input['finished_at'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return [0, json_encode([
                    'success' => [
                        'data' => [
                            'run' => [
                                'id' => (int) $database->lastInsertId(),
                                'schedule_key' => (string) ($input['schedule_key'] ?? ''),
                                'node' => $caller['name'],
                                'status' => (string) ($input['status'] ?? 'failed'),
                                'exit_code' => $input['exit_code'] ?? null,
                                'started_at' => (string) ($input['started_at'] ?? $now),
                                'finished_at' => $input['finished_at'] ?? null,
                                'output' => [
                                    'stdout' => (string) ($input['stdout'] ?? ''),
                                    'stderr' => (string) ($input['stderr'] ?? ''),
                                ],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
            } catch (Throwable $exception) {
                return [1, $exception->getMessage()];
            }
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
                            'wireguard' => preg_replace('/\.2$/', '.3', $gatewayIp),
                        ],
                    ],
                    'gateway' => [
                        'name' => 'gateway',
                        'role' => 'gateway',
                        'status' => 'active',
                        'platform' => 'unknown',
                        'addresses' => [
                            'wireguard' => $gatewayIp,
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
        
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $orbitPath.'/storage/app/orbit/certs/'.$gatewayIp.'.crt',
                'local_pk' => $orbitPath.'/storage/app/orbit/certs/'.$gatewayIp.'.key',
                'allow_self_signed' => true,
                'verify_peer' => false,
            ],
        ]);
        
        $server = stream_socket_server('tls://'.$gatewayIp.':443', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        
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

            if (str_starts_with($requestLine, 'GET /api/schedules/sync ')) {
                [$exitCode, $output] = run_schedule_sync();
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

            if (str_starts_with($requestLine, 'POST /api/schedules/heartbeat ')) {
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

                [$exitCode, $output] = run_schedule_heartbeat($input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
                fclose($connection);

                continue;
            }

            if (str_starts_with($requestLine, 'POST /api/schedules/runs ')) {
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

                [$exitCode, $output] = run_schedule_run_store($input);
                respond($connection, $exitCode === 0 ? 200 : 422, $output);
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
        
                $parts = ['php artisan node:list --json'];
        
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
        
                $parts = ['php artisan app:list --json'];
        
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
        
            respond($connection, 404, '');
            fclose($connection);
        }
        PHP_WRAP;
    }

    private static function stopServerScript(): string
    {
        return <<<'PHP'
$matches = static function (string $command): bool {
    if (str_contains($command, 'php -r')) {
        return false;
    }

    $isPhpProcess = str_contains($command, 'php ');
    $isGatewayHttp = str_contains($command, 'php artisan serve --host=') && str_contains($command, '--port=80');
    $isGatewayTls = str_contains($command, '/tmp/orbit-') && str_contains($command, '-tls.php');

    return $isPhpProcess && ($isGatewayHttp || $isGatewayTls);
};

$pids = [];

foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
    $pid = (int) basename(dirname($file));

    if ($pid <= 1 || $pid === getmypid()) {
        continue;
    }

    $cmdline = @file_get_contents($file);

    if (! is_string($cmdline) || $cmdline === '') {
        continue;
    }

    $command = str_replace("\0", ' ', $cmdline);

    if ($matches($command)) {
        $pids[] = $pid;
        @posix_kill($pid, 15);
    }
}

usleep(200000);

foreach ($pids as $pid) {
    @posix_kill($pid, 9);
}
PHP;
    }
}
