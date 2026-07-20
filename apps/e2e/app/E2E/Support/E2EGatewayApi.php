<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EGatewayApi
{
    private const string OrbitConfigRoot = '/home/orbit/.config/orbit';

    private const string GatewayContainerOrbitPath = '/srv/orbit';

    private const string HostOrbitCliPath = '/usr/local/bin/orbit';

    private const string GatewayContainerOrbitCliPath = '/usr/local/bin/orbit-cli';

    private const string GatewayLocalExecutorContainer = 'orbit-gateway';

    private const string SourceMountedGatewayContainerOrbitCliPath = '/srv/orbit/apps/cli/orbit';

    private const string GatewayWireGuardHttpUrl = 'http://10.6.0.2';

    private const string WgEasyStatePath = '/home/orbit/.wg-easy';

    public static function seedOperatorIdentity(
        E2EInstance $gateway,
        string $operatorIp,
        string $operatorUser,
        string $gatewayIp = '10.6.0.2',
        string $operatorWireGuardIp = '10.6.0.3',
    ): void {
        $operatorIpValue = var_export($operatorIp, true);
        $operatorUserValue = var_export($operatorUser, true);
        $gatewayIpValue = var_export($gatewayIp, true);
        $operatorWireGuardIpValue = var_export($operatorWireGuardIp, true);
        $orbitPathValue = var_export("/home/{$operatorUser}/orbit", true);

        $php = <<<PHP
            \$operator = \\App\\Models\\Node::query()->updateOrCreate(
                ['name' => 'operator-1'],
                array_merge(
                    [
                        'tld' => 'operator',
                        'platform' => 'ubuntu',
                        'host' => {$operatorIpValue},
                        'wireguard_address' => {$operatorWireGuardIpValue},
                        'gateway_endpoint' => {$gatewayIpValue},
                        'user' => {$operatorUserValue},
                        'orbit_path' => {$orbitPathValue},
                        'status' => 'active',
                    ],
                    \\Illuminate\\Support\\Facades\\Schema::hasColumn('nodes', 'ssh_user') ? ['ssh_user' => {$operatorUserValue}] : [],
                ),
            );

            \$gateway = app(\\App\\Services\\Nodes\\Roles\\NodeRoleAssignments::class)
                ->activeGatewayNodeQuery()
                ->firstOrFail();

            \\App\\Models\\NodeAccess::query()->updateOrCreate(
                [
                    'consumer_node_id' => \$operator->id,
                    'serving_node_id' => \$gateway->id,
                ],
                [
                    'permissions' => ['*'],
                    'custom_permissions' => [],
                ],
            );
            PHP;

        if (! self::isDockerTopology($gateway)) {
            E2ECommand::gatewayArtisan(
                $gateway,
                self::tinkerEvalArguments($php),
                'Could not seed operator identity on gateway',
            );

            return;
        }

        $rootCommands = [
            'cd /home/orbit/orbit',
            'export ORBIT_CONFIG_ROOT='.escapeshellarg(self::OrbitConfigRoot),
            self::repairGatewayConfigRootOwnershipCommand(),
            self::dockerGatewayStateBootstrapCommand(),
            self::repairGatewayConfigRootOwnershipCommand(),
        ];
        $orbitCommands = [
            'cd /home/orbit/orbit',
            'export ORBIT_CONFIG_ROOT='.escapeshellarg(self::OrbitConfigRoot),
            self::appKeyCommand(self::gatewayStatePath('.env')),
            'php apps/gateway/artisan migrate --force --no-interaction --ansi',
            self::sudoRepairGatewayConfigRootOwnershipCommand(),
            self::tinkerEvalCommand($php),
        ];
        E2ECommand::exec(
            $gateway,
            implode(' && ', $rootCommands)
                .' && sudo -iu orbit env ORBIT_GATEWAY_CONTAINER="${ORBIT_GATEWAY_CONTAINER:-}" ORBIT_E2E_DOCKER_NETWORK="${ORBIT_E2E_DOCKER_NETWORK:-}" ORBIT_CONFIG_ROOT="${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}" DB_CONNECTION="${DB_CONNECTION:-sqlite}" DB_DATABASE="${DB_DATABASE:-/home/orbit/.config/orbit/gateway.sqlite}" SESSION_DRIVER="${SESSION_DRIVER:-file}" bash -lc '
                .escapeshellarg(implode(' && ', $orbitCommands)),
            'Could not seed operator identity on gateway',
        );
        self::repairGatewayConfigRootOwnership($gateway);
    }

    public static function prepareDockerGatewayState(
        E2EInstance $gateway,
        string $orbitPath = '/home/orbit/orbit',
        ?string $gatewayWireGuardAddress = null,
        ?string $viewCompiledPath = null,
    ): void {
        if (! self::isDockerTopology($gateway)) {
            return;
        }

        self::repairGatewayConfigRootOwnership($gateway);

        $commands = [
            'cd '.escapeshellarg($orbitPath),
            'export ORBIT_CONFIG_ROOT='.escapeshellarg(self::OrbitConfigRoot),
            self::dockerGatewayStateBootstrapCommand(),
        ];

        if ($viewCompiledPath !== null) {
            $commands[] = self::dockerGatewayEnvironmentCommand($viewCompiledPath);
        }

        $commands[] = self::appKeyCommand(self::gatewayStatePath('.env'));
        $commands[] = 'php apps/gateway/artisan migrate --force --no-interaction --ansi';

        if ($gatewayWireGuardAddress !== null) {
            $commands[] = sprintf(
                'php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway %s --tld=gateway --skip-gateway-service-install --skip-wireguard-install',
                escapeshellarg($gatewayWireGuardAddress),
            );
        }

        E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            implode(' && ', $commands),
            timeoutSeconds: 180,
        );
        self::repairGatewayConfigRootOwnership($gateway);
    }

    public static function sourceMountedGatewayStateCommand(): string
    {
        return implode(' && ', [
            'export ORBIT_CONFIG_ROOT='.escapeshellarg(self::OrbitConfigRoot),
            self::sudoRepairGatewayConfigRootOwnershipCommand(),
            self::dockerGatewayStateBootstrapCommand(),
            self::sudoRepairGatewayConfigRootOwnershipCommand(),
            self::appKeyCommand(self::gatewayStatePath('.env')),
            self::sourceMountedGatewayLocalExecutorCommand(),
            'php apps/gateway/artisan migrate --force --no-interaction --ansi',
        ]);
    }

    public static function startSourceMountedGatewayLocalExecutor(
        E2EInstance $gateway,
        string $orbitPath = '/home/orbit/orbit',
    ): void {
        $hostOrbitPath = self::gatewaySourceMountedRuntimePath($gateway, $orbitPath) ?? self::gatewayHostOrbitPath(
            $gateway,
            $orbitPath,
        );
        E2ECommand::exec(
            $gateway,
            self::gatewayE2eContainerCommand(
                container: self::GatewayLocalExecutorContainer,
                script: 'exec sleep infinity',
                hostOrbitPath: $hostOrbitPath,
                matchGatewayConfigOwner: true,
            ),
            'Could not start source-mounted gateway-local executor container',
            timeoutSeconds: 120,
        );

        E2ECommand::exec(
            $gateway,
            sprintf(
                'docker exec --workdir %s %s %s internal:wg-easy:state --action=ensure-writable --json 2>&1'
                .' | tee /dev/stderr | grep -q %s',
                escapeshellarg(self::GatewayContainerOrbitPath.'/apps/gateway'),
                escapeshellarg(self::GatewayLocalExecutorContainer),
                escapeshellarg(self::SourceMountedGatewayContainerOrbitCliPath),
                escapeshellarg('"code":"missing_token"'),
            ),
            'Source-mounted gateway-local executor command is not available',
            timeoutSeconds: 30,
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
        self::installGatewayContainerSshKeyIfExists(
            $gateway,
            self::isDockerTopology($gateway) ? self::gatewayContainerName($gateway) : 'orbit-gateway',
        );
    }

    private static function installSshKeyIfUserExists(
        E2EInstance $gateway,
        SshKeyPair $key,
        string $user,
        string $home,
    ): void {
        if (! $gateway->exec('id -u '.escapeshellarg($user).' >/dev/null 2>&1')->successful()) {
            return;
        }

        self::installSshKey($gateway, $key, $user, $home);
    }

    private static function installSshKey(E2EInstance $gateway, SshKeyPair $key, string $user, string $home): void
    {
        $sshDirectory = "{$home}/.ssh";
        $privateKey = "{$sshDirectory}/id_ed25519";
        $publicKey = "{$privateKey}.pub";

        E2ECommand::exec(
            $gateway,
            sprintf(
                'install -d -m 700 -o %s -g %s %s && rm -f %s %s',
                escapeshellarg($user),
                escapeshellarg($user),
                escapeshellarg($sshDirectory),
                escapeshellarg($privateKey),
                escapeshellarg($publicKey),
            ),
            "Could not prepare {$user} SSH directory on gateway",
        );

        $gateway->copyFileToInstance($key->privateKeyPath, $privateKey);
        $gateway->copyFileToInstance($key->publicKeyPath, $publicKey);

        E2ECommand::exec(
            $gateway,
            sprintf(
                'chown %s:%s %s %s && chmod 600 %s && chmod 644 %s',
                escapeshellarg($user),
                escapeshellarg($user),
                escapeshellarg($privateKey),
                escapeshellarg($publicKey),
                escapeshellarg($privateKey),
                escapeshellarg($publicKey),
            ),
            "Could not install {$user} SSH key on gateway",
        );
    }

    private static function installGatewayContainerSshKeyIfExists(E2EInstance $gateway, string $container): void
    {
        $containerArgument = escapeshellarg($container);
        $containerPrivateKey = escapeshellarg("{$container}:/root/.ssh/id_ed25519");
        $containerPublicKey = escapeshellarg("{$container}:/root/.ssh/id_ed25519.pub");

        $script = <<<SH
            set -e
            if sudo docker inspect --format='{{.State.Running}}' {$containerArgument} 2>/dev/null | grep -qx true; then
                gateway_private_key=/home/orbit/.ssh/id_ed25519
                if [ ! -f "\$gateway_private_key" ]; then
                    gateway_private_key=/root/.ssh/id_ed25519
                fi
                if [ -f "\$gateway_private_key" ]; then
                    sudo docker exec {$containerArgument} sh -lc 'install -d -m 700 /root/.ssh'
                    sudo docker cp "\$gateway_private_key" {$containerPrivateKey}
                    if [ -f "\${gateway_private_key}.pub" ]; then
                        sudo docker cp "\${gateway_private_key}.pub" {$containerPublicKey}
                    fi
                    sudo docker exec {$containerArgument} sh -lc 'chown root:root /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /root/.ssh/id_ed25519.pub ]; then chown root:root /root/.ssh/id_ed25519.pub && chmod 644 /root/.ssh/id_ed25519.pub; fi'
                fi
            fi
            SH;

        E2ECommand::exec(
            $gateway,
            $script,
            'Could not install provisioning SSH key in gateway container',
            timeoutSeconds: 60,
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
        self::start(
            $gateway,
            $label,
            $orbitPath,
            $gatewayIp,
            $wireguardIdentity,
            $bindAddress,
            $certKey,
            $certSans,
            $peerIdentityMap,
        );
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
            self::startDocker(
                $gateway,
                $label,
                $orbitPath,
                $wireguardIdentity,
                $bindAddress,
                $certKey,
                $certSans,
                $peerIdentityMap,
            );

            return;
        }

        self::startIncusGatewayContainerShim(
            gateway: $gateway,
            label: $label,
            orbitPath: $orbitPath,
            wireguardIdentity: $wireguardIdentity,
            bindAddress: $bindAddress,
            certKey: $certKey,
            certSans: $certSans,
            peerIdentityMap: $peerIdentityMap,
        );
    }

    /**
     * @param  array<string, string>  $peerIdentityMap
     */
    private static function startIncusGatewayContainerShim(
        E2EInstance $gateway,
        string $label,
        string $orbitPath,
        string $wireguardIdentity,
        string $bindAddress,
        string $certKey,
        array $certSans,
        array $peerIdentityMap,
    ): void {
        $certKeyValue = var_export($certKey, true);
        $certSansValue = var_export(array_values($certSans), true);
        $httpRouterPath = "/tmp/orbit-{$label}-http-router.php";
        $tlsScriptPath = "/tmp/orbit-{$label}-tls.php";
        $httpContainer = self::gatewayE2eContainerName($label, 'http');
        $tlsContainer = self::gatewayE2eContainerName($label, 'tls');
        $hostOrbitPath = self::gatewaySourceMountedRuntimePath($gateway, $orbitPath) ?? self::gatewayHostOrbitPath(
            $gateway,
            $orbitPath,
        );
        $hostCliPath = self::gatewayHostOrbitCliPath($hostOrbitPath);

        E2ECommand::gatewayArtisan(
            $gateway,
            self::tinkerEvalArguments(
                "app(\\App\\Services\\Ca\\OrbitCaService::class)->issueLeaf({$certKeyValue}, {$certSansValue}); echo 'issued';",
            ),
            'Could not issue gateway leaf certificate',
        );

        E2ECommand::exec(
            $gateway,
            'docker stop orbit-caddy >/dev/null 2>&1 || true',
            'Could not stop gateway orbit-caddy before starting gateway test servers',
        );

        self::prepareRootRemoteShellIdentity($gateway);

        E2ECommand::exec(
            $gateway,
            self::gatewayE2eContainerCommand(
                container: $httpContainer,
                script: 'cat > '
                .escapeshellarg($httpRouterPath)
                ." <<'PHP'\n"
                .self::httpRouterScript(self::GatewayContainerOrbitPath, $peerIdentityMap)
                ."\nPHP\ncd "
                .escapeshellarg(self::GatewayContainerOrbitPath.'/apps/gateway')
                ."\nexec php -d display_errors=0 -d max_execution_time=0 -S {$bindAddress}:80 -t public {$httpRouterPath}",
                mountHostCli: true,
                mountSsh: true,
                hostCliPath: $hostCliPath,
                hostOrbitPath: $hostOrbitPath,
            ),
            'Could not start gateway HTTP API',
            timeoutSeconds: 120,
        );

        E2ECommand::exec(
            $gateway,
            self::gatewayE2eContainerCommand(
                container: $tlsContainer,
                script: 'cat > '
                    .escapeshellarg($tlsScriptPath)
                    ." <<'PHP'\n"
                    .self::tlsServerScript(
                        self::GatewayContainerOrbitPath,
                        $wireguardIdentity,
                        $bindAddress,
                        $certKey,
                        $peerIdentityMap,
                        dockerGatewayContainer: true,
                        orbitCommand: self::GatewayContainerOrbitCliPath,
                    )
                    ."\nPHP\nexec php "
                    .escapeshellarg($tlsScriptPath),
                mountHostCli: true,
                mountSsh: true,
                hostCliPath: $hostCliPath,
                hostOrbitPath: $hostOrbitPath,
            ),
            'Could not start gateway TLS test server',
            timeoutSeconds: 120,
        );
    }

    public static function stop(E2EInstance $gateway): void
    {
        if (self::isDockerTopology($gateway)) {
            $gateway->exec(sprintf(
                'sudo docker exec %s sh -lc %s >/dev/null 2>&1 || true',
                escapeshellarg(self::gatewayContainerName($gateway)),
                escapeshellarg(self::stopServerShellScript()),
            ));

            return;
        }

        $gateway->exec(
            "docker ps -aq --filter 'name=^/orbit-gateway-e2e-' | xargs -r docker rm -f >/dev/null 2>&1 || true",
        );
        $gateway->exec('sh -lc '.escapeshellarg(self::stopServerShellScript()));
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
        $wireguardIdentityValue = var_export($wireguardIdentity, true);
        $viewCompiledPath = "{$orbitPath}/apps/gateway/storage/framework/views";
        $gatewayContainer = escapeshellarg(self::gatewayContainerName($gateway));
        $scriptPath = "/tmp/orbit-{$label}-tls.php";
        $httpRouterPath = "/tmp/orbit-{$label}-http-router.php";
        $scriptPathArgument = escapeshellarg($scriptPath);
        $httpRouterPathArgument = escapeshellarg($httpRouterPath);
        $certificatePayload = <<<'PHP'
            $hostKey = app(\App\Services\Security\SshHostKeyPinner::class)->pin('gateway');
            $node = \App\Models\Node::query()->updateOrCreate(
                ['name' => 'gateway'],
                [
                    'host' => 'gateway',
                    'wireguard_address' => __WIREGUARD_IDENTITY__,
                    'gateway_endpoint' => 'https://gateway',
                    'user' => 'orbit',
                    'orbit_path' => '/home/orbit/orbit',
                    'status' => 'active',
                    'host_key_type' => $hostKey->type,
                    'host_key_fingerprint' => $hostKey->fingerprint,
                    'host_key_public' => $hostKey->publicKey,
                    'host_key_pin_mode' => $hostKey->pinMode,
                    'host_key_pinned_at' => now(),
                ],
            );
            \App\Models\NodeRoleAssignment::query()->updateOrCreate(
                ['node_id' => $node->id, 'role' => 'gateway'],
                ['status' => 'active'],
            );
            $ca = app(\App\Services\Ca\OrbitCaService::class);
            $ca->ensureRootCa();
            $ca->issueLeaf(__CERT_KEY__, __CERT_SANS__);
            echo 'issued';
            PHP;
        $certificatePayload = str_replace(
            ['__CERT_KEY__', '__CERT_SANS__', '__WIREGUARD_IDENTITY__'],
            [$certKeyValue, $certSansValue, $wireguardIdentityValue],
            $certificatePayload,
        );

        self::prepareDockerGatewayState($gateway, $orbitPath, $wireguardIdentity, $viewCompiledPath);

        $certificateCommand = implode(' && ', [
            self::dockerGatewayStateReadyCommand(),
            self::appKeyCommand(self::gatewayStatePath('.env')),
            'DB_CONNECTION=sqlite DB_DATABASE='
                .escapeshellarg(self::gatewayStatePath('gateway.sqlite'))
                .' SESSION_DRIVER=file php apps/gateway/artisan migrate --force --no-interaction --ansi',
            'DB_CONNECTION=sqlite DB_DATABASE='
                .escapeshellarg(self::gatewayStatePath('gateway.sqlite'))
                .' SESSION_DRIVER=file php apps/gateway/artisan tinker --execute='
                .escapeshellarg($certificatePayload),
        ]);

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --env %s --env %s --workdir %s %s sh -lc %s',
                escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
                escapeshellarg('ORBIT_CONFIG_ROOT='.self::OrbitConfigRoot),
                $orbitPathArgument,
                $gatewayContainer,
                escapeshellarg($certificateCommand),
            ),
            'Could not issue gateway leaf certificate',
            timeoutSeconds: 180,
        );

        self::repairGatewayConfigRootOwnership($gateway);
        self::prepareRootRemoteShellIdentity($gateway);
        self::prepareDockerGatewayRemoteShellIdentity($gateway);

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --workdir %s %s sh -lc %s',
                $orbitPathArgument,
                $gatewayContainer,
                escapeshellarg(
                    "cat > {$scriptPathArgument} <<'PHP'\n"
                    .self::tlsServerScript(
                        $orbitPath,
                        $wireguardIdentity,
                        $bindAddress,
                        $certKey,
                        $peerIdentityMap,
                        dockerGatewayContainer: true,
                    )
                    ."\nPHP",
                ),
            ),
            'Could not write gateway TLS test server in gateway container',
            timeoutSeconds: 60,
        );

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --workdir %s %s sh -lc %s',
                $orbitPathArgument,
                $gatewayContainer,
                escapeshellarg(
                    "cat > {$httpRouterPathArgument} <<'PHP'\n"
                    .self::httpRouterScript($orbitPath, $peerIdentityMap)
                    ."\nPHP",
                ),
            ),
            'Could not write gateway HTTP test router in gateway container',
            timeoutSeconds: 60,
        );

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --detach --env %s --env %s --env %s --env %s --workdir %s %s php -d display_errors=0 -d max_execution_time=0 -S %s:80 -t apps/gateway/public %s',
                escapeshellarg("VIEW_COMPILED_PATH={$viewCompiledPath}"),
                escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
                escapeshellarg('ORBIT_CONFIG_ROOT='.self::OrbitConfigRoot),
                escapeshellarg('PHP_CLI_SERVER_WORKERS=4'),
                $orbitPathArgument,
                $gatewayContainer,
                escapeshellarg($bindAddress),
                $httpRouterPathArgument,
            ),
            'Could not start gateway HTTP API',
            timeoutSeconds: 60,
        );

        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec --detach --env %s --env %s --workdir %s %s php apps/gateway/artisan tinker --execute=%s',
                escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
                escapeshellarg('ORBIT_CONFIG_ROOT='.self::OrbitConfigRoot),
                $orbitPathArgument,
                $gatewayContainer,
                escapeshellarg('include '.var_export($scriptPath, true).';'),
            ),
            'Could not start gateway TLS test server',
            timeoutSeconds: 60,
        );
    }

    public static function waitForGatewayApi(
        E2EInstance $operator,
        string $operatorUser,
        SshKeyPair $key,
        string $gatewayIp = '10.6.0.2',
    ): void {
        $deadline = time() + 120;
        $last = null;
        $lastException = null;

        while (time() < $deadline) {
            try {
                $last = $operator->ssh(
                    $operatorUser,
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
            \$node = \\App\\Models\\Node::query()
                ->with('roleAssignments')
                ->where('name', {$nameValue})
                ->firstOrFail();

            echo json_encode(\$node->only([
                'name',
                'tld',
                'host',
                'wireguard_address',
                'gateway_endpoint',
                'user',
            ]) + [
                'roles' => \$node->roleAssignments
                    ->where('status', \\App\\Enums\\Nodes\\NodeRoleStatus::Active->value)
                    ->sortBy('role')
                    ->values()
                    ->map(fn (\\App\\Models\\NodeRoleAssignment \$assignment): array => [
                        'role' => \$assignment->role,
                        'status' => \$assignment->status,
                    ])
                    ->all(),
            ], JSON_THROW_ON_ERROR);
            PHP;

        $result = ! self::isDockerTopology($gateway)
            ? E2ECommand::gatewayArtisan(
                $gateway,
                self::tinkerEvalArguments($php),
                "Could not read gateway node {$name}",
            )
            : E2ECommand::orbit(
                $gateway,
                'cd /home/orbit/orbit && '.self::tinkerEvalCommand($php),
                "Could not read gateway node {$name}",
            );

        return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private static function tinkerEvalCommand(string $php): string
    {
        return 'php apps/gateway/artisan '.self::tinkerEvalArguments($php);
    }

    private static function tinkerEvalArguments(string $php): string
    {
        $encoded = base64_encode($php);

        return 'tinker --execute='.escapeshellarg("eval(base64_decode('{$encoded}'));");
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

    private static function prepareDockerGatewayRemoteShellIdentity(E2EInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            sprintf(
                'sudo docker exec %s sh -lc %s',
                escapeshellarg(self::gatewayContainerName($gateway)),
                escapeshellarg(self::prepareRootRemoteShellIdentityScript()),
            ),
            'Could not prepare gateway container RemoteShell identity for gateway HTTP API',
            timeoutSeconds: 60,
        );
    }

    private static function prepareRootRemoteShellIdentityScript(): string
    {
        return 'if [ -f /home/orbit/.ssh/id_ed25519 ]; then install -d -m 700 /root/.ssh && cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /home/orbit/.ssh/id_ed25519.pub ]; then cp /home/orbit/.ssh/id_ed25519.pub /root/.ssh/id_ed25519.pub; fi; fi';
    }

    /**
     * @param  array<string, string>  $peerIdentityMap
     */
    private static function httpRouterScript(string $orbitPath, array $peerIdentityMap = []): string
    {
        return (
            "<?php\n\n\$orbitPath = "
            .var_export($orbitPath, true)
            .";\n\$peerIdentityMap = "
            .var_export($peerIdentityMap, true)
            .";\n\n"
            .<<<'PHP_WRAP'
                function http_router_normalize_peer_ip(string $peerIp): string
                {
                    $peerIp = trim($peerIp, '[]');

                    return str_starts_with(strtolower($peerIp), '::ffff:')
                        ? substr($peerIp, 7)
                        : $peerIp;
                }

                function http_router_peer_identity(): ?string
                {
                    global $peerIdentityMap;

                    $peerIp = $_SERVER['REMOTE_ADDR'] ?? null;

                    if (! is_string($peerIp) || $peerIp === '') {
                        return null;
                    }

                    $peerIp = http_router_normalize_peer_ip($peerIp);

                    if (filter_var($peerIp, FILTER_VALIDATE_IP) === false) {
                        return null;
                    }

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

                if (! isset($_SERVER['HTTP_X_ORBIT_E2E_WIREGUARD_IP'])) {
                    $identity = http_router_peer_identity();

                    if ($identity !== null) {
                        $_SERVER['HTTP_X_ORBIT_E2E_WIREGUARD_IP'] = $identity;
                    }
                }

                $publicPath = $orbitPath.'/apps/gateway/public';
                $uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '');

                if ($uri !== '/' && is_file($publicPath.$uri)) {
                    return false;
                }

                require $publicPath.'/index.php';
                PHP_WRAP
        );
    }

    /**
     * @param  array<string, string>  $peerIdentityMap
     */
    private static function tlsServerScript(
        string $orbitPath,
        string $wireguardIdentity,
        string $bindAddress,
        string $certKey,
        array $peerIdentityMap = [],
        bool $dockerGatewayContainer = false,
        string $orbitCommand = 'orbit',
    ): string {
        $httpUpstream = self::httpUpstreamForBindAddress($bindAddress);
        $certDirectory = $dockerGatewayContainer
            ? self::gatewayStatePath('certs')
            : self::gatewayStatePath('certs');
        $runOrbitCommand = $dockerGatewayContainer
            ? "exec(\$script.' 2>&1', \$output, \$exitCode);"
            : "exec('sudo -iu orbit bash -lc '.escapeshellarg(\$script).' 2>&1', \$output, \$exitCode);";
        $runOrbitScript = $dockerGatewayContainer
            ? '$configRoot = '
            .var_export(self::OrbitConfigRoot, true)
            .";\n            \$viewCompiledPath = \$orbitPath.'/apps/gateway/storage/framework/views';\n            \$stateDirectories = "
            .var_export([
                'apps/gateway/storage/framework/cache/data',
                'apps/gateway/storage/framework/sessions',
                'apps/gateway/storage/framework/testing',
                'apps/gateway/storage/framework/views',
                'apps/gateway/storage/logs',
            ], true)
            .";\n            \$script = 'cd '.escapeshellarg(\$orbitPath).' && export ORBIT_CONFIG_ROOT='.escapeshellarg(\$configRoot).' && mkdir -p '.implode(' ', array_map('escapeshellarg', \$stateDirectories)).' && VIEW_COMPILED_PATH='.escapeshellarg(\$viewCompiledPath).' '.\$command;"
            : "\$script = 'cd '.escapeshellarg(\$orbitPath).' && mkdir -p apps/gateway/storage/framework/cache/data apps/gateway/storage/framework/sessions apps/gateway/storage/framework/testing apps/gateway/storage/framework/views apps/gateway/storage/logs && VIEW_COMPILED_PATH='.escapeshellarg(\$orbitPath.'/apps/gateway/storage/framework/views').' '.\$command;";

        $script =
            "<?php\n\n\$orbitPath = "
            .var_export($orbitPath, true)
            .";\n\$orbitCommand = "
            .var_export($orbitCommand, true)
            .";\n\$wireguardIdentity = "
            .var_export($wireguardIdentity, true)
            .";\n\$bindAddress = "
            .var_export($bindAddress, true)
            .";\n\$certKey = "
            .var_export($certKey, true)
            .";\n\$certDirectory = "
            .var_export($certDirectory, true)
            .";\n\$httpUpstream = "
            .var_export($httpUpstream, true)
            .";\n\$peerIdentityMap = "
            .var_export($peerIdentityMap, true)
            .";\n\$GLOBALS['orbitPath'] = \$orbitPath;\n\$GLOBALS['orbitCommand'] = \$orbitCommand;\n\$GLOBALS['wireguardIdentity'] = \$wireguardIdentity;\n\$GLOBALS['httpUpstream'] = \$httpUpstream;\n\$GLOBALS['peerIdentityMap'] = \$peerIdentityMap;\n\n"
            .<<<'PHP_WRAP'

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
                    global $orbitCommand, $orbitPath;

                    $output = [];
                    $exitCode = 0;
                    $command = preg_replace('/^orbit(?=\s)/', escapeshellarg($orbitCommand), $command, 1);

                    if (! is_string($command)) {
                        return [1, 'Could not prepare Orbit command.'];
                    }

                    __RUN_ORBIT_SCRIPT__
                    __RUN_ORBIT_COMMAND__

                    return [$exitCode, implode("\n", $output)];
                }

                function run_node_new(array $input): array
                {
                    $parts = [
                        'orbit node:new',
                        escapeshellarg((string) ($input['name'] ?? '')),
                        '--host='.escapeshellarg((string) ($input['host'] ?? '')),
                        '--user='.escapeshellarg((string) ($input['user'] ?? 'root')),
                        '--json',
                    ];

                    if (($input['template'] ?? null) !== null && $input['template'] !== '') {
                        $parts[] = '--template='.escapeshellarg((string) $input['template']);
                    }

                    if (($input['roles'] ?? null) !== null && $input['roles'] !== '') {
                        $roles = is_array($input['roles'])
                            ? implode(',', array_map('strval', $input['roles']))
                            : (string) $input['roles'];

                        $parts[] = '--roles='.escapeshellarg($roles);
                    }

                    if (($input['operator'] ?? false) === true) {
                        $parts[] = '--operator';
                    }

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
                    if (($input['environment'] ?? null) !== null && $input['environment'] !== '') {
                        return [1, json_encode([
                            'error' => [
                                'code' => 'validation_failed',
                                'message' => "Field 'environment' is not supported for node:update.",
                                'meta' => ['field' => 'environment'],
                            ],
                        ], JSON_THROW_ON_ERROR)];
                    }

                    $parts = [
                        'orbit node:update',
                        escapeshellarg($name),
                        '--json',
                    ];

                    foreach ([
                        'host' => 'host',
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
                    return run_orbit_command('orbit project:show '.escapeshellarg($name).' --json');
                }

                function run_app_new(array $input): array
                {
                    $parts = [
                        'orbit project:new',
                        escapeshellarg((string) ($input['name'] ?? '')),
                        '--node='.escapeshellarg((string) ($input['node'] ?? '')),
                        '--root='.escapeshellarg((string) ($input['root'] ?? 'public')),
                        '--php-version='.escapeshellarg((string) ($input['php_version'] ?? '8.5')),
                        '--json',
                    ];

                    foreach ([
                        'repository' => 'repo',
                        'template_repository' => 'template-repo',
                        'new_repository' => 'new-repo',
                        'domain' => 'domain',
                    ] as $field => $option) {
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
                        'orbit instance:register',
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
                        'orbit instance:root '
                            .escapeshellarg($name).' '
                            .escapeshellarg((string) ($input['root'] ?? '')).' --json'
                    );
                }

                function run_app_agent_ide(string $name, array $input): array
                {
                    return run_orbit_command(
                        'orbit instance:agent-ide '
                            .escapeshellarg($name).' '
                            .escapeshellarg((string) ($input['agent_ide'] ?? '')).' --json'
                    );
                }

                function run_app_remove(string $name): array
                {
                    return run_orbit_command('orbit project:remove '.escapeshellarg($name).' --force --json');
                }

                function run_activity_list(array $query): array
                {
                    $parts = ['orbit activity:list --json'];

                    foreach (['project', 'node', 'effect', 'correlation', 'limit'] as $option) {
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

                    foreach (['instance', 'limit', 'since', 'until'] as $option) {
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
                        'instance' => 'instance',
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

                    foreach (['instance' => 'instance', 'path' => 'path'] as $field => $option) {
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
                    $instance = $query['instance'] ?? null;
                    $path = $query['path'] ?? null;

                    if (is_scalar($instance) && (string) $instance !== '') {
                        $parts[] = '--instance='.escapeshellarg((string) $instance);
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
                        'instance' => 'instance',
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

                    foreach (['instance' => 'instance'] as $field => $option) {
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

                    $instance = $query['instance'] ?? null;

                    if (is_scalar($instance) && (string) $instance !== '') {
                        $parts[] = '--instance='.escapeshellarg((string) $instance);
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
                                'name' => 'operator-1',
                                'roles' => [],
                                'status' => 'active',
                                'platform' => 'ubuntu',
                                'addresses' => [
                                    'wireguard' => preg_replace('/\.2$/', '.3', $wireguardIdentity),
                                ],
                            ],
                            'gateway' => [
                                'name' => 'gateway',
                                'roles' => [
                                    [
                                        'role' => 'gateway',
                                        'status' => 'active',
                                        'settings' => [],
                                        'last_error' => null,
                                        'converged_at' => null,
                                    ],
                                ],
                                'status' => 'active',
                                'platform' => 'ubuntu',
                                'addresses' => [
                                    'wireguard' => $wireguardIdentity,
                                ],
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES);

                $context = stream_context_create([
                    'ssl' => [
                        'local_cert' => $certDirectory.'/'.$certKey.'.crt',
                        'local_pk' => $certDirectory.'/'.$certKey.'.key',
                        'allow_self_signed' => true,
                        'verify_peer' => false,
                    ],
                ]);

                $server = stream_socket_server('tls://'.$bindAddress.':443', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

                if ($server === false) {
                    fwrite(STDERR, "Could not start TLS server: {$errstr}\n");
                    exit(1);
                }

                $workerCount = 4;

                for ($worker = 1; $worker < $workerCount; $worker++) {
                    $workerPid = pcntl_fork();

                    if ($workerPid === -1) {
                        fwrite(STDERR, "Could not fork gateway TLS worker.\n");
                        exit(1);
                    }

                    if ($workerPid === 0) {
                        break;
                    }
                }

                while (true) {
                    $connection = @stream_socket_accept($server, -1);

                    if ($connection === false) {
                        continue;
                    }

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

                    if (preg_match('#^(GET|POST|PUT|PATCH|DELETE) /api/#', $requestLine) === 1) {
                        $body = read_request_body($connection, $headers);
                        proxy_to_laravel($connection, $requestLine, $headers, $body);
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

                    if (str_starts_with($requestLine, 'GET /api/nodes ') || str_starts_with($requestLine, 'GET /api/nodes?')) {
                        $path = explode(' ', $requestLine)[1] ?? '/api/nodes';
                        $queryString = parse_url($path, PHP_URL_QUERY);
                        $query = [];

                        if (is_string($queryString)) {
                            parse_str($queryString, $query);
                        }

                        $parts = ['orbit node:list --json'];

                        if (($query['environment'] ?? null) !== null && $query['environment'] !== '') {
                            respond($connection, 422, json_encode([
                                'error' => [
                                    'code' => 'validation_failed',
                                    'message' => 'Node environment filters are not supported. Filter by role instead.',
                                    'meta' => ['field' => 'environment'],
                                ],
                            ], JSON_THROW_ON_ERROR));
                            fclose($connection);

                            continue;
                        }

                        foreach (['role'] as $option) {
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

                    if (str_starts_with($requestLine, 'GET /api/projects ') || str_starts_with($requestLine, 'GET /api/projects?')) {
                        $path = explode(' ', $requestLine)[1] ?? '/api/projects';
                        $queryString = parse_url($path, PHP_URL_QUERY);
                        $query = [];

                        if (is_string($queryString)) {
                            parse_str($queryString, $query);
                        }

                        $parts = ['orbit project:list --json'];

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

                    if (preg_match('#^GET /api/projects/([^ ?]+)#', $requestLine, $matches) === 1) {
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

                    if (preg_match('#^POST /api/instances/([^ ?]+)/agent-ide#', $requestLine, $matches) === 1) {
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

                    if (preg_match('#^POST /api/instances/([^ ?]+)/root#', $requestLine, $matches) === 1) {
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

                    if (preg_match('#^DELETE /api/projects/([^ ?]+)#', $requestLine, $matches) === 1) {
                        read_request_body($connection, $headers);

                        [$exitCode, $output] = run_app_remove(urldecode($matches[1]));
                        respond($connection, $exitCode === 0 ? 200 : 422, $output);
                        fclose($connection);

                        continue;
                    }

                    if (str_starts_with($requestLine, 'POST /api/instances/register ')) {
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

                    if (str_starts_with($requestLine, 'POST /api/projects ')) {
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
            ['__RUN_ORBIT_SCRIPT__', '__RUN_ORBIT_COMMAND__'],
            [$runOrbitScript, $runOrbitCommand],
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

    private static function dockerGatewayStateBootstrapCommand(): string
    {
        $gatewayEnv = escapeshellarg(self::gatewayStatePath('.env'));
        $gatewayEnvTmp = escapeshellarg(self::gatewayStatePath('.env.tmp'));
        $gatewayDatabase = escapeshellarg(self::gatewayStatePath('gateway.sqlite'));

        return implode(' && ', [
            'mkdir -p '
                .escapeshellarg(self::OrbitConfigRoot)
                .' apps/gateway/storage/framework/cache/data apps/gateway/storage/framework/sessions apps/gateway/storage/framework/testing apps/gateway/storage/framework/views apps/gateway/storage/logs',
            "if [ ! -f {$gatewayEnv} ]; then cp apps/gateway/.env.example {$gatewayEnv}; fi",
            "rm -f {$gatewayEnvTmp}",
            "grep -Ev '^(DB_DATABASE|SESSION_DRIVER)=' {$gatewayEnv} > {$gatewayEnvTmp} || true",
            "mv {$gatewayEnvTmp} {$gatewayEnv}",
            "printf '\\nDB_DATABASE=%s\\nSESSION_DRIVER=file\\n' {$gatewayDatabase} >> {$gatewayEnv}",
            "touch {$gatewayDatabase}",
        ]);
    }

    private static function repairGatewayConfigRootOwnership(E2EInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            self::repairGatewayConfigRootOwnershipCommand(),
            'Could not repair gateway config root ownership',
            timeoutSeconds: 60,
        );
    }

    private static function repairGatewayConfigRootOwnershipCommand(): string
    {
        return (
            'install -d -m 775 -o orbit -g orbit '
            .escapeshellarg(self::OrbitConfigRoot)
            .' && chown -R orbit:orbit '
            .escapeshellarg(self::OrbitConfigRoot)
            .' && chmod -R u+rwX,g+rwX '
            .escapeshellarg(self::OrbitConfigRoot)
        );
    }

    private static function sudoRepairGatewayConfigRootOwnershipCommand(): string
    {
        return (
            'sudo install -d -m 775 -o orbit -g orbit '
            .escapeshellarg(self::OrbitConfigRoot)
            .' && sudo chown -R orbit:orbit '
            .escapeshellarg(self::OrbitConfigRoot)
            .' && sudo chmod -R u+rwX,g+rwX '
            .escapeshellarg(self::OrbitConfigRoot)
        );
    }

    private static function dockerGatewayStateReadyCommand(): string
    {
        $gatewayEnv = escapeshellarg(self::gatewayStatePath('.env'));
        $gatewayDatabase = escapeshellarg(self::gatewayStatePath('gateway.sqlite'));

        return implode(' && ', [
            self::dockerGatewayStateBootstrapCommand(),
            "test -f {$gatewayEnv}",
            "test -f {$gatewayDatabase}",
        ]);
    }

    private static function dockerGatewayEnvironmentCommand(string $viewCompiledPath): string
    {
        $gatewayEnv = escapeshellarg(self::gatewayStatePath('.env'));
        $gatewayEnvTmp = escapeshellarg(self::gatewayStatePath('.env.tmp'));
        $viewCompiledPathArgument = escapeshellarg($viewCompiledPath);

        return implode(' && ', [
            "rm -f {$gatewayEnvTmp}",
            "grep -Ev '^(ORBIT_E2E_TRUST_WIREGUARD_HEADER|VIEW_COMPILED_PATH|ORBIT_E2E_TOPOLOGY_PROVIDER)=' {$gatewayEnv} > {$gatewayEnvTmp} || true",
            "mv {$gatewayEnvTmp} {$gatewayEnv}",
            "printf '\\nORBIT_E2E_TRUST_WIREGUARD_HEADER=true\\nVIEW_COMPILED_PATH=%s\\nORBIT_E2E_TOPOLOGY_PROVIDER=docker\\n' {$viewCompiledPathArgument} >> {$gatewayEnv}",
        ]);
    }

    private static function gatewayStatePath(string $path): string
    {
        return self::OrbitConfigRoot."/{$path}";
    }

    private static function gatewayContainerName(E2EInstance $instance): string
    {
        return "{$instance->name()}-orbit-gateway";
    }

    private static function gatewayE2eContainerName(string $label, string $role): string
    {
        $slug = preg_replace('/[^a-z0-9_.-]+/i', '-', strtolower($label));

        if (! is_string($slug) || trim($slug, '-_.') === '') {
            $slug = 'gateway';
        }

        return "orbit-gateway-e2e-{$slug}-{$role}";
    }

    private static function gatewayE2eContainerCommand(
        string $container,
        string $script,
        bool $mountHostCli = false,
        bool $mountSsh = false,
        ?string $hostCliPath = null,
        ?string $hostOrbitPath = null,
        bool $matchGatewayConfigOwner = false,
    ): string {
        $arguments = [
            'docker run --rm --detach --pull never',
            '--name '.escapeshellarg($container),
            '--network host',
            '--env '.escapeshellarg('ORBIT_CONFIG_ROOT='.self::OrbitConfigRoot),
            '--env '.escapeshellarg('DB_CONNECTION=sqlite'),
            '--env '.escapeshellarg('DB_DATABASE='.self::gatewayStatePath('gateway.sqlite')),
            '--env '.escapeshellarg('SESSION_DRIVER=file'),
            '--env '.escapeshellarg('ORBIT_E2E_TRUST_WIREGUARD_HEADER=true'),
            '--env '.escapeshellarg('ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1'),
            '--env '
                .escapeshellarg(
                    'VIEW_COMPILED_PATH='.self::GatewayContainerOrbitPath.'/apps/gateway/storage/framework/views',
                ),
            '--env '.escapeshellarg('HOME=/home/orbit'),
            '--env '.escapeshellarg('PHP_CLI_SERVER_WORKERS=4'),
            '--mount '.escapeshellarg('type=bind,source='.self::OrbitConfigRoot.',target='.self::OrbitConfigRoot),
            '--mount '.escapeshellarg('type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock'),
        ];

        if ($matchGatewayConfigOwner) {
            $arguments[] = '--user "$(stat -c %u:%g '.escapeshellarg(self::OrbitConfigRoot).')"';
            $arguments[] = '--group-add "$(stat -c %g /var/run/docker.sock)"';
        }

        if ($hostOrbitPath !== null) {
            $arguments[] =
                '--mount '.escapeshellarg("type=bind,source={$hostOrbitPath},target=".self::GatewayContainerOrbitPath);
        }

        if ($mountHostCli) {
            $hostCliPath ??= self::HostOrbitCliPath;

            $arguments[] = '--env '.escapeshellarg('ORBIT_GATEWAY_URL='.self::GatewayWireGuardHttpUrl);
            $arguments[] = '--env '.escapeshellarg('ORBIT_GATEWAY_E2E_CLI='.self::GatewayContainerOrbitCliPath);
            $arguments[] = '--env '.escapeshellarg('ORBIT_FORWARD_INSTALL_BINARY='.self::GatewayContainerOrbitCliPath);
            $arguments[] = '--env '.escapeshellarg('ORBIT_LOCAL_EXECUTOR_BINARY='.self::GatewayContainerOrbitCliPath);
            $arguments[] = '-v '.escapeshellarg($hostCliPath.':'.self::GatewayContainerOrbitCliPath.':ro');
            $arguments[] = '-v '.escapeshellarg(self::WgEasyStatePath.':'.self::WgEasyStatePath);
        }

        if ($mountSsh) {
            $arguments[] = '-v '.escapeshellarg('/home/orbit/.ssh:/home/orbit/.ssh:ro');
            $arguments[] = '-v '.escapeshellarg('/root/.ssh:/root/.ssh:ro');
        }

        $arguments[] = '"$gateway_e2e_image"';
        $arguments[] = 'sh -lc '.escapeshellarg($script);

        return (
            self::gatewayE2eImageSelectionCommand()
            .' && docker rm -f '
            .escapeshellarg($container)
            .' >/dev/null 2>&1 || true && '
            .implode(' ', $arguments)
        );
    }

    private static function gatewayImage(): string
    {
        return DockerTopologyProvider::gatewayImage();
    }

    private static function sourceGatewayArtisanImage(): string
    {
        return DockerTopologyProvider::sourceGatewayArtisanImage();
    }

    private static function gatewayE2eImageSelectionCommand(): string
    {
        return sprintf(
            'gateway_e2e_image=$(if [ -f %s ] && [ -f %s ]; then printf %%s %s; else printf %%s %s; fi)',
            escapeshellarg(self::OrbitConfigRoot.'/source-mounted-runtime'),
            escapeshellarg('/home/orbit/orbit/apps/gateway/artisan'),
            escapeshellarg(self::sourceGatewayArtisanImage()),
            escapeshellarg(self::gatewayImage()),
        );
    }

    private static function gatewayHostOrbitCliPath(?string $hostOrbitPath): string
    {
        if ($hostOrbitPath !== null) {
            return "{$hostOrbitPath}/apps/cli/orbit";
        }

        return self::HostOrbitCliPath;
    }

    private static function gatewayHostOrbitPath(E2EInstance $gateway, string $orbitPath): ?string
    {
        $orbitPath = rtrim($orbitPath, '/');

        if ($orbitPath !== '/home/orbit/orbit') {
            return $orbitPath;
        }

        $sourceMountedCheckout = self::gatewaySourceMountedCheckoutPath($gateway);

        if ($sourceMountedCheckout !== null) {
            return $sourceMountedCheckout;
        }

        return null;
    }

    private static function gatewaySourceMountedRuntimePath(E2EInstance $gateway, string $orbitPath): ?string
    {
        $orbitPath = rtrim($orbitPath, '/');

        $result = $gateway->exec(sprintf(
            'test -f %s && test -f %s && printf %%s %s',
            escapeshellarg(self::OrbitConfigRoot.'/source-mounted-runtime'),
            escapeshellarg("{$orbitPath}/apps/gateway/artisan"),
            escapeshellarg($orbitPath),
        ), timeoutSeconds: 10);

        $path = trim($result->output());

        return $result->successful() && $path === $orbitPath
            ? $orbitPath
            : null;
    }

    private static function gatewaySourceMountedCheckoutPath(E2EInstance $gateway): ?string
    {
        if (! $gateway instanceof SourceMountedCheckoutInstance) {
            return null;
        }

        $checkout = $gateway->sourceMountedCheckoutPath();

        return is_string($checkout) && $checkout !== ''
            ? rtrim($checkout, '/')
            : null;
    }

    private static function appKeyCommand(?string $envPath = null): string
    {
        $envPath = escapeshellarg($envPath ?? self::gatewayStatePath('.env'));

        return implode(' && ', [
            "(grep -q '^APP_KEY=' {$envPath} || printf '%s\\n' 'APP_KEY=' >> {$envPath})",
            "(grep -Eq '^APP_KEY=base64:.+' {$envPath} || php apps/gateway/artisan key:generate --force --no-interaction)",
            "grep -Eq '^APP_KEY=base64:.+' {$envPath}",
        ]);
    }

    private static function sourceMountedGatewayLocalExecutorCommand(): string
    {
        $environmentPath = escapeshellarg(self::gatewayStatePath('.env'));
        $environmentLine = escapeshellarg(
            'ORBIT_LOCAL_EXECUTOR_BINARY='.self::SourceMountedGatewayContainerOrbitCliPath,
        );

        return sprintf(
            "(grep -q '^ORBIT_LOCAL_EXECUTOR_BINARY=' %1\$s && sed -i %2\$s %1\$s || printf '%%s\\n' %3\$s >> %1\$s)",
            $environmentPath,
            escapeshellarg(
                's|^ORBIT_LOCAL_EXECUTOR_BINARY=.*|ORBIT_LOCAL_EXECUTOR_BINARY='
                .self::SourceMountedGatewayContainerOrbitCliPath
                .'|',
            ),
            $environmentLine,
        );
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
                    */tmp/orbit-*-http-router.php*|*/tmp/orbit-*-tls.php*|*orbit\ serve\ --host=*--port=80*|*php\ */apps/gateway/artisan\ serve\ --host=*--port=80*|*php\ -S\ *:80\ */apps/gateway/vendor/laravel/framework/src/Illuminate/Foundation/Console/../resources/server.php*)
                        pids="$pids $pid"
                        kill -TERM "$pid" >/dev/null 2>&1 || true
                        ;;
                esac
            done

            sleep 0.2

            for pid in $pids; do
                kill -KILL "$pid" >/dev/null 2>&1 || true
            done

            exit 0
            SH;
    }
}
