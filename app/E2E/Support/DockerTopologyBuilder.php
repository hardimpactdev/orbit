<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class DockerTopologyBuilder
{
    private const string RuntimeImage = 'orbit-e2e-topology-runtime:current';

    public function __construct(
        private E2EConfig $config,
    ) {}

    /**
     * @return list<array{role: string, container: string, image: string}>
     */
    public function build(E2ETopologyKind $kind, string $mode = 'legacy-retarget'): array
    {
        $network = "{$this->config->instancePrefix}-build-{$kind->value}";
        $roles = self::rolesFor($kind);
        $containers = [];
        $composerLockHash = $this->composerLockHash();
        $certSanSet = $this->certSanSetForMode($mode);

        $this->mustRun(
            sprintf('docker image inspect %s >/dev/null', escapeshellarg(self::RuntimeImage)),
            'Docker topology runtime image is missing',
        );

        try {
            $this->mustRun(
                sprintf('docker network create --subnet %s %s', escapeshellarg('10.6.0.0/16'), escapeshellarg($network)),
                "Could not create Docker build network {$network}",
            );

            foreach ($roles as $role) {
                $container = "{$network}-{$role}";
                $containers[$role] = new DockerBuildInstance($container, $network);

                $this->mustRun($this->runCommand($container, $network, $this->ipForRole($role)), "Could not start {$container}");
                $this->migrate($containers[$role], $role);
            }

            $this->seedTopology($kind, $containers, $mode);

            $manifest = [];

            foreach ($roles as $role) {
                $container = "{$network}-{$role}";
                $image = self::imageNameFor($kind, $role, $mode);

                if (! self::shouldCommitRole($kind, $role)) {
                    $manifest[] = [
                        'role' => $role,
                        'container' => $container,
                        'image' => $image,
                        'reused' => true,
                    ];

                    continue;
                }

                $this->mustRun(
                    sprintf(
                        'docker commit --change %s --change %s --change %s --change %s --change %s --change %s %s %s',
                        escapeshellarg('CMD ["/usr/local/bin/orbit-e2e-container"]'),
                        escapeshellarg("LABEL org.orbit.e2e.topology-mode={$mode}"),
                        escapeshellarg("LABEL org.orbit.e2e.kind={$kind->value}"),
                        escapeshellarg("LABEL org.orbit.e2e.role={$role}"),
                        escapeshellarg("LABEL org.orbit.e2e.composer-lock={$composerLockHash}"),
                        escapeshellarg("LABEL org.orbit.e2e.cert-san-set={$certSanSet}"),
                        escapeshellarg($container),
                        escapeshellarg($image),
                    ),
                    "Could not commit {$container}",
                    timeoutSeconds: 600,
                );

                $manifest[] = [
                    'role' => $role,
                    'container' => $container,
                    'image' => $image,
                ];
            }

            return $manifest;
        } finally {
            foreach (array_keys($containers) as $role) {
                $this->run(sprintf('docker rm -f %s >/dev/null 2>&1 || true', escapeshellarg("{$network}-{$role}")), timeoutSeconds: 120);
            }

            $this->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 60);
        }
    }

    /**
     * @return list<string>
     */
    public static function rolesFor(E2ETopologyKind $kind): array
    {
        return match ($kind) {
            E2ETopologyKind::Operator => ['control'],
            E2ETopologyKind::OperatorGateway => ['control', 'gateway'],
            E2ETopologyKind::OperatorGatewayAppdev => ['control', 'gateway', 'dev'],
            E2ETopologyKind::OperatorGatewayAppdevAppprod => ['control', 'gateway', 'dev', 'prod'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => ['control', 'gateway', 'dev', 'prod', 'agent'],
        };
    }

    public static function imageNameFor(E2ETopologyKind $kind, string $role, string $mode = 'legacy-retarget'): string
    {
        $effectiveKind = self::imageKindFor($kind, $role);

        if ($mode === 'legacy-retarget') {
            return "orbit-e2e-topology:{$effectiveKind->value}-{$role}-current";
        }

        return "orbit-e2e-topology:{$effectiveKind->value}-{$role}-{$mode}-current";
    }

    private static function imageKindFor(E2ETopologyKind $kind, string $role): E2ETopologyKind
    {
        return $kind;
    }

    private static function shouldCommitRole(E2ETopologyKind $kind, string $role): bool
    {
        return self::ownsImage($kind, $role);
    }

    public static function ownsImage(E2ETopologyKind $kind, string $role): bool
    {
        return self::imageKindFor($kind, $role) === $kind;
    }

    private function runCommand(string $container, string $network, string $ip): string
    {
        return sprintf(
            'docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name %s --network %s --ip %s %s',
            escapeshellarg($container),
            escapeshellarg($network),
            escapeshellarg($ip),
            escapeshellarg(self::RuntimeImage),
        );
    }

    private function migrate(DockerBuildInstance $instance, string $role): void
    {
        $user = $role === 'control' ? 'control' : 'orbit';
        $path = $role === 'control' ? '/home/control/orbit' : '/home/orbit/orbit';

        E2ECommand::ssh($instance, $user, new SshKeyPair('/dev/null', '/dev/null'), "cd {$path} && php artisan migrate --force", timeoutSeconds: 120);
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function seedTopology(E2ETopologyKind $kind, array $containers, string $mode): void
    {
        $control = $containers['control'] ?? null;
        $gateway = $containers['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        $key = new SshKeyPair('/dev/null', '/dev/null');

        E2ECommand::ssh($gateway, 'orbit', $key, 'cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2 --skip-runtime-install', timeoutSeconds: 120);
        E2ECommand::ssh($gateway, 'orbit', $key, 'cd /home/orbit/orbit && php artisan doctor --node=gateway --family=schedule --restore --json', timeoutSeconds: 120);
        E2EGatewayApi::seedControlIdentity($gateway, '10.6.0.3', 'control');
        if ($mode === 'dns-alias') {
            E2EGatewayApi::start(
                $gateway,
                "docker-build-{$kind->value}",
                wireguardIdentity: '10.6.0.2',
                bindAddress: '0.0.0.0',
                certKey: 'gateway',
                certSans: ['10.6.0.2'],
            );
        } else {
            E2EGatewayApi::start($gateway, "docker-build-{$kind->value}");
        }

        E2EGatewayApi::waitForGatewayApi($control, 'control', $key);
        E2EControlIdentity::ensure($control, 'control', $key);
        E2ECommand::ssh($control, 'control', $key, 'cd /home/control/orbit && php artisan gateway:add 10.6.0.2 --json', timeoutSeconds: 600);

        if ($mode === 'dns-alias') {
            E2ECommand::ssh(
                $control,
                'control',
                $key,
                'cd /home/control/orbit && php artisan tinker --execute='.escapeshellarg("App\\Models\\LocalGatewaySettings::current()->fill(['gateway_url' => 'https://gateway'])->save();"),
                timeoutSeconds: 60,
            );
        }

        $this->seedRemoteShellSshAccess($gateway, $containers);
        $this->seedRemoteShellAgentSshAccess($gateway, $containers);

        if (isset($containers['dev'])) {
            $host = $mode === 'dns-alias' ? 'dev' : '10.6.0.4';
            $gatewayEndpoint = $mode === 'dns-alias' ? 'gateway' : '10.6.0.2';

            E2ECommand::ssh($gateway, 'orbit', $key, "cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-dev-1 --role=app --host={$host} --wireguard-address=10.6.0.4 --environment=development --tld=test --gateway-endpoint={$gatewayEndpoint} --user=orbit", timeoutSeconds: 120);
        }

        if (isset($containers['prod'])) {
            $host = $mode === 'dns-alias' ? 'prod' : '10.6.0.5';
            $gatewayEndpoint = $mode === 'dns-alias' ? 'gateway' : '10.6.0.2';

            E2ECommand::ssh($gateway, 'orbit', $key, "cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-prod-1 --role=app --host={$host} --wireguard-address=10.6.0.5 --environment=production --gateway-endpoint={$gatewayEndpoint} --user=orbit", timeoutSeconds: 120);
        }

        if (isset($containers['agent'])) {
            $host = $mode === 'dns-alias' ? 'agent' : '10.6.0.6';
            $gatewayEndpoint = $mode === 'dns-alias' ? 'gateway' : '10.6.0.2';

            E2ECommand::ssh($gateway, 'orbit', $key, "cd /home/orbit/orbit && php artisan orbit:internal:bake-agent-node agent-1 --host={$host} --wireguard-address=10.6.0.6 --tld=agent --gateway-endpoint={$gatewayEndpoint} --user=orbit", timeoutSeconds: 120);
        }
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function seedRemoteShellSshAccess(DockerBuildInstance $gateway, array $containers): void
    {
        if (! isset($containers['dev']) && ! isset($containers['prod'])) {
            return;
        }

        $publicKey = $this->gatewayPublicKey($gateway);

        foreach (['dev', 'prod'] as $role) {
            if (! isset($containers[$role])) {
                continue;
            }

            $this->authorizeGatewaySshKey($containers[$role], $publicKey);
        }
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function seedRemoteShellAgentSshAccess(DockerBuildInstance $gateway, array $containers): void
    {
        if (! isset($containers['agent'])) {
            return;
        }

        $publicKey = $this->gatewayPublicKey($gateway);

        $this->authorizeGatewaySshKey($containers['agent'], $publicKey);
    }

    private function gatewayPublicKey(DockerBuildInstance $gateway): string
    {
        $result = E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            'install -d -m 700 ~/.ssh && if ! test -f ~/.ssh/id_ed25519; then ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C orbit-e2e-gateway >/dev/null; fi && cat ~/.ssh/id_ed25519.pub',
            timeoutSeconds: 60,
        );

        $publicKey = trim($result->output());

        if ($publicKey === '') {
            throw new RuntimeException('Could not create Docker gateway SSH key for RemoteShell E2E access.');
        }

        return $publicKey;
    }

    private function authorizeGatewaySshKey(DockerBuildInstance $instance, string $publicKey): void
    {
        E2ECommand::exec(
            $instance,
            sprintf(
                'install -d -m 700 -o orbit -g orbit /home/orbit/.ssh && touch /home/orbit/.ssh/authorized_keys && chown orbit:orbit /home/orbit/.ssh/authorized_keys && chmod 600 /home/orbit/.ssh/authorized_keys && grep -qxF %1$s /home/orbit/.ssh/authorized_keys || printf "%%s\n" %1$s >> /home/orbit/.ssh/authorized_keys',
                escapeshellarg($publicKey),
            ),
            "Could not authorize gateway SSH key in {$instance->name()}",
            timeoutSeconds: 60,
        );
    }

    private function ipForRole(string $role): string
    {
        return match ($role) {
            'gateway' => '10.6.0.2',
            'control' => '10.6.0.3',
            'dev' => '10.6.0.4',
            'prod' => '10.6.0.5',
            'agent' => '10.6.0.6',
            default => throw new RuntimeException("Unknown Docker topology role {$role}."),
        };
    }

    private function composerLockHash(): string
    {
        $path = base_path('composer.lock');

        return is_file($path) ? hash_file('sha256', $path) : 'missing';
    }

    private function certSanSetForMode(string $mode): string
    {
        return $mode === 'dns-alias'
            ? 'DNS:gateway,IP:10.6.0.2'
            : 'IP:10.6.0.2';
    }

    private function mustRun(string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $this->run($command, $timeoutSeconds);

        if ($result->successful()) {
            return $result;
        }

        throw new RuntimeException("{$message}: {$result->output()}{$result->errorOutput()}");
    }

    private function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        if ($timeoutSeconds !== null) {
            return Process::timeout($timeoutSeconds)->run($command);
        }

        return Process::run($command);
    }
}

final readonly class DockerBuildInstance implements E2EInstance
{
    public function __construct(
        private string $name,
        private ?string $network = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->run(sprintf(
            'docker exec %s sh -lc %s',
            escapeshellarg($this->name),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->run(sprintf(
            'docker exec --user %s %s sh -lc %s',
            escapeshellarg($user),
            escapeshellarg($this->name),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

    public function copyFileToInstance(string $sourcePath, string $targetPath): void
    {
        $this->run(sprintf(
            'docker cp %s %s:%s',
            escapeshellarg($sourcePath),
            escapeshellarg($this->name),
            escapeshellarg($targetPath),
        ));
    }

    public function waitForAgent(): void {}

    public function waitForIpv4(): string
    {
        if ($this->network === null) {
            throw new RuntimeException("Docker network is unknown for {$this->name}.");
        }

        $result = $this->run(sprintf(
            'docker inspect -f %s %s',
            escapeshellarg('{{(index .NetworkSettings.Networks "'.$this->network.'").IPAddress}}'),
            escapeshellarg($this->name),
        ));

        if (! $result->successful()) {
            throw new RuntimeException("Could not inspect Docker container {$this->name}: {$result->errorOutput()}");
        }

        return trim($result->output());
    }

    public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

    public function delete(): void
    {
        $this->run(sprintf('docker rm -f %s >/dev/null 2>&1 || true', escapeshellarg($this->name)), timeoutSeconds: 60);
    }

    private function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        if ($timeoutSeconds !== null) {
            return Process::timeout($timeoutSeconds)->run($command);
        }

        return Process::run($command);
    }
}
