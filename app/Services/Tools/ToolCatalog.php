<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolCatalog
{
    private const array SUPPORTED = [
        'caddy',
        'supervisor',
        'docker',
        'viteplus',
        'php-cli',
        'gh',
        'composer',
        'dns',
        'php',
        'postgres',
        'mysql',
        'redis',
        'mailpit',
        'reverb',
        'polyscope-server',
        'opencode-server',
    ];

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return self::SUPPORTED;
    }

    public function supports(string $tool): bool
    {
        return in_array($tool, self::SUPPORTED, true);
    }

    public function hasRepairCommand(string $tool, string $key): bool
    {
        return $this->repairCommand($tool, $key) !== null;
    }

    public function repairCommand(string $tool, string $key): ?string
    {
        $metadata = $this->probeMetadata($tool);
        $commands = is_array($metadata) && is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];
        $command = $commands[$key] ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }

    public function logCommand(string $tool, int $lines, bool $follow = false): ?string
    {
        $metadata = $this->probeMetadata($tool);
        $service = is_array($metadata) && is_string($metadata['service'] ?? null)
            ? $metadata['service']
            : null;

        if ($service === null || $service === '') {
            return null;
        }

        $lineCount = max(1, $lines);

        if (! $follow) {
            return sprintf(
                'sudo bash -lc %s',
                escapeshellarg(sprintf(
                    'output="$(%s 2>/dev/null | sed "/^-- No entries --$/d")"; if [ -n "$output" ]; then printf "%%s\n" "$output"; else systemctl status %s --no-pager --lines=%d 2>/dev/null || true; fi',
                    $this->journalctlCommand($service, $lineCount),
                    escapeshellarg($service),
                    $lineCount,
                )),
            );
        }

        return sprintf(
            'sudo %s',
            $this->journalctlCommand($service, $lineCount, follow: true),
        );
    }

    private function journalctlCommand(string $service, int $lines, bool $follow = false): string
    {
        $unit = str_contains($service, '.') ? $service : "{$service}.service";

        return sprintf(
            'journalctl _SYSTEMD_UNIT=%s + SYSLOG_IDENTIFIER=%s -n %d%s --no-pager --output=short-iso',
            escapeshellarg($unit),
            escapeshellarg($service),
            $lines,
            $follow ? ' -f' : '',
        );
    }

    /**
     * @return list<string>
     */
    public function capabilities(string $tool): array
    {
        if (! $this->supports($tool)) {
            return [];
        }

        return match ($tool) {
            'redis', 'mailpit', 'reverb', 'postgres', 'mysql' => [
                'install', 'remove', 'start', 'stop', 'restart', 'update', 'logs', 'credentials', 'safe-fix', 'safe-adopt',
            ],
            'php' => ['install', 'remove', 'update'],
            'polyscope-server' => ['install', 'remove', 'start', 'stop', 'restart', 'update', 'reconfigure', 'safe-fix'],
            'opencode-server' => ['install', 'remove', 'start', 'stop', 'restart', 'update', 'reconfigure', 'credentials', 'safe-fix'],
            default => [],
        };
    }

    public function hasCapability(string $tool, string $capability): bool
    {
        return in_array($capability, $this->capabilities($tool), true);
    }

    public function installScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'install')) {
            return null;
        }

        return match ($tool) {
            'redis', 'mailpit', 'reverb', 'postgres', 'mysql' => $this->dockerComposeInstallScript($tool, $config),
            'opencode-server' => $this->opencodeServerInstallScript($config),
            'polyscope-server' => $this->polyscopeServerInstallScript($config),
            default => null,
        };
    }

    public function removeScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'remove')) {
            return null;
        }

        return match ($tool) {
            'redis', 'mailpit', 'reverb', 'postgres', 'mysql' => $this->dockerComposeRemoveScript($tool, $config),
            default => null,
        };
    }

    public function updateScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'update')) {
            return null;
        }

        return match ($tool) {
            'redis', 'mailpit', 'reverb', 'postgres', 'mysql' => $this->dockerComposeInstallScript($tool, $config),
            'composer', 'caddy', 'gh' => $this->probeMetadata($tool)['update_command'] ?? null,
            default => null,
        };
    }

    /** @phpstan-ignore return.unusedType */
    public function latestSupportedVersion(string $tool): ?string
    {
        if (! $this->supports($tool)) {
            return null;
        }

        return null;
    }

    public function credentialsScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'credentials')) {
            return null;
        }

        return match ($tool) {
            'opencode-server' => $this->opencodeServerCredentialsScript($config),
            default => null,
        };
    }

    public function reconfigureScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'reconfigure')) {
            return null;
        }

        return match ($tool) {
            'opencode-server' => $this->opencodeServerReconfigureScript($config),
            'polyscope-server' => $this->polyscopeServerReconfigureScript(),
            default => null,
        };
    }

    private function opencodeServerInstallScript(array $config): string
    {
        $port = (int) ($config['port'] ?? 4096);
        $hostname = $config['hostname'] ?? '127.0.0.1';
        $username = $config['username'] ?? 'opencode';
        $password = $config['password'] ?? null;

        $authEnv = $password === null || $password === ''
            ? ''
            : "Environment=OPENCODE_SERVER_USERNAME={$username}\n        Environment=OPENCODE_SERVER_PASSWORD={$password}\n        ";

        return <<<"BASH"
#!/usr/bin/env bash
# orbit install opencode-server
set -e
curl -fsSL https://opencode.ai/install | bash
user=$(whoami)
home=$(echo \$HOME)
unitDir="\${home}/.config/systemd/user"
unitPath="\${unitDir}/opencode-server.service"
mkdir -p "\${unitDir}"
cat > "\${unitPath}" <<UNIT
[Unit]
Description=OpenCode Server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
        {$authEnv}ExecStart=\${home}/.opencode/bin/opencode serve --hostname {$hostname} --port {$port}
Restart=on-failure
RestartSec=5

[Install]
WantedBy=default.target
UNIT
sudo loginctl enable-linger "\${user}"
export XDG_RUNTIME_DIR=/run/user/\$(id -u)
systemctl --user daemon-reload
systemctl --user enable opencode-server
systemctl --user start opencode-server
BASH;
    }

    private function polyscopeServerInstallScript(array $config): string
    {
        $localTarget = $config['local_target'] ?? false;
        $guidance = $localTarget ? '' : <<<'GUIDE'

echo ""
echo "  Polyscope Server installed but authentication is required."
echo "  Run the following on this node to authenticate:"
echo "    polyscope-server login"
echo ""
GUIDE;

        return <<<"BASH"
#!/usr/bin/env bash
# orbit install polyscope-server
set -e
curl -fsSL https://getpolyscope.com/install/server | bash
user=$(whoami)
home=$(echo \$HOME)
unitDir="\${home}/.config/systemd/user"
unitPath="\${unitDir}/polyscope-server.service"
mkdir -p "\${unitDir}"
path=$(bash -lc 'echo \$PATH')
userBin="\${home}/.local/bin"
if [[ ":\${path}:" != *":\${userBin}:"* ]]; then
  path="\${userBin}:\${path}"
fi
cat > "\${unitPath}" <<UNIT
[Unit]
Description=Polyscope Server
After=network.target

[Service]
Type=simple
Environment=HOME=\${home}
Environment="PATH=\${path}"
ExecStart=\${home}/.local/bin/polyscope-server
Restart=on-failure
RestartSec=5

[Install]
WantedBy=default.target
UNIT
sudo loginctl enable-linger "\${user}"
export XDG_RUNTIME_DIR=/run/user/\$(id -u)
systemctl --user daemon-reload
systemctl --user enable polyscope-server
systemctl --user start polyscope-server
{$guidance}
BASH;
    }

    private function opencodeServerCredentialsScript(array $config): string
    {
        $hostname = $config['hostname'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 4096);
        $username = $config['username'] ?? 'opencode';
        $password = $config['password'] ?? null;

        $authUsername = $password === null || $password === '' ? '(no auth)' : $username;
        $authPassword = $password === null || $password === '' ? '(no auth)' : $password;

        return <<<"BASH"
cat <<EOF
{
  "Host": "{$hostname}",
  "Port": "{$port}",
  "Username": "{$authUsername}",
  "Password": "{$authPassword}"
}
EOF
BASH;
    }

    private function opencodeServerReconfigureScript(array $config): string
    {
        $port = (int) ($config['port'] ?? 4096);
        $hostname = $config['hostname'] ?? '127.0.0.1';
        $username = $config['username'] ?? 'opencode';
        $password = $config['password'] ?? null;

        $authEnv = $password === null || $password === ''
            ? ''
            : "Environment=OPENCODE_SERVER_USERNAME={$username}\n        Environment=OPENCODE_SERVER_PASSWORD={$password}\n        ";

        return <<<"BASH"
#!/usr/bin/env bash
# orbit reconfigure opencode-server
set -e
home=$(echo \$HOME)
unitPath="\${home}/.config/systemd/user/opencode-server.service"
mkdir -p "\${home}/.config/systemd/user"
cat > "\${unitPath}" <<UNIT
[Unit]
Description=OpenCode Server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
        {$authEnv}ExecStart=\${home}/.opencode/bin/opencode serve --hostname {$hostname} --port {$port}
Restart=on-failure
RestartSec=5

[Install]
WantedBy=default.target
UNIT
export XDG_RUNTIME_DIR=/run/user/\$(id -u)
systemctl --user daemon-reload
systemctl --user restart opencode-server
BASH;
    }

    private function polyscopeServerReconfigureScript(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit reconfigure polyscope-server
set -e
home=$(echo $HOME)
unitPath="${home}/.config/systemd/user/polyscope-server.service"
if [ -f "$unitPath" ]; then
  export XDG_RUNTIME_DIR=/run/user/$(id -u)
  systemctl --user daemon-reload
  systemctl --user restart polyscope-server
fi
BASH;
    }

    private function dockerComposeInstallScript(string $service, array $config): string
    {
        $composePath = $config['compose_path'] ?? '/opt/orbit/docker-compose.yml';

        return "docker compose -f '{$composePath}' pull '{$service}' && docker compose -f '{$composePath}' up -d '{$service}'";
    }

    private function dockerComposeRemoveScript(string $service, array $config): string
    {
        $composePath = $config['compose_path'] ?? '/opt/orbit/docker-compose.yml';

        return "docker compose -f '{$composePath}' stop '{$service}' && docker compose -f '{$composePath}' rm -f '{$service}'";
    }

    /**
     * @return array{
     *     binary: string,
     *     version_command?: string,
     *     service?: string,
     *     update_command?: string,
     *     repair_commands?: array<string, string>,
     * }|null
     */
    public function probeMetadata(string $tool): ?array
    {
        if (! $this->supports($tool)) {
            return null;
        }

        return match ($tool) {
            'redis' => ['binary' => 'redis-server', 'version_command' => 'redis-server --version', 'service' => 'redis-server', 'repair_commands' => $this->serviceRepairCommands('redis-server', restart: true)],
            'php', 'php-cli' => ['binary' => 'php', 'version_command' => 'php -r "echo PHP_VERSION;"'],
            'composer' => ['binary' => 'composer', 'version_command' => 'composer --version', 'update_command' => 'sudo composer self-update 2>/dev/null'],
            'caddy' => ['binary' => 'caddy', 'version_command' => 'caddy version', 'service' => 'caddy', 'update_command' => 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get update -qq && sudo apt-get install --only-upgrade -y caddy 2>/dev/null', 'repair_commands' => $this->serviceRepairCommands('caddy', restart: true, reload: 'sudo caddy reload --config /etc/caddy/Caddyfile')],
            'supervisor' => ['binary' => 'supervisord', 'service' => 'supervisor', 'repair_commands' => $this->serviceRepairCommands('supervisor', reload: 'sudo supervisorctl reread')],
            'docker' => ['binary' => 'docker', 'version_command' => 'docker --version', 'service' => 'docker', 'repair_commands' => $this->serviceRepairCommands('docker', restart: true)],
            'gh' => ['binary' => 'gh', 'version_command' => 'gh --version', 'update_command' => 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get install --only-upgrade -y gh 2>/dev/null'],
            'mysql' => ['binary' => 'mysql', 'version_command' => 'mysql --version'],
            'postgres' => ['binary' => 'psql', 'version_command' => 'psql --version'],
            default => ['binary' => $tool],
        };
    }

    /**
     * @return array<string, string>
     */
    private function serviceRepairCommands(string $service, bool $restart = false, ?string $reload = null): array
    {
        $commands = [
            'lifecycle_running' => "sudo systemctl start {$service}",
            'lifecycle_stopped' => "sudo systemctl stop {$service}",
        ];

        if ($restart) {
            $commands['lifecycle_restarted'] = "sudo systemctl restart {$service}";
        }

        if ($reload !== null) {
            $commands['lifecycle_reloaded'] = $reload;
        }

        return $commands;
    }
}
