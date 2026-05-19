<?php

declare(strict_types=1);

namespace App\Tools;

final class OpenCodeServerTool extends BaseTool
{
    public function slug(): string
    {
        return 'opencode-server';
    }

    #[\Override]
    public function category(): string
    {
        return 'development';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'start', 'stop', 'restart', 'update', 'reconfigure', 'credentials', 'safe-fix'];
    }

    public function installScript(array $config = []): string
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

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove opencode-server
set -e
home=$(echo $HOME)
export XDG_RUNTIME_DIR=/run/user/$(id -u)
systemctl --user stop opencode-server 2>/dev/null || true
systemctl --user disable opencode-server 2>/dev/null || true
rm -f "${home}/.config/systemd/user/opencode-server.service"
systemctl --user daemon-reload
sudo systemctl stop opencode-server 2>/dev/null || true
sudo systemctl disable opencode-server 2>/dev/null || true
sudo rm -f /etc/systemd/system/opencode-server.service
sudo systemctl daemon-reload
rm -rf "${home}/.opencode"
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit update opencode-server
set -e
home=$(echo $HOME)
export XDG_RUNTIME_DIR=/run/user/$(id -u)
"${home}/.opencode/bin/opencode" upgrade
systemctl --user restart opencode-server
BASH;
    }

    public function credentialsScript(array $config = []): string
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

    public function reconfigureScript(array $config = []): string
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

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'opencode',
            'service' => 'opencode-server',
            'repair_commands' => $this->serviceRepairCommands('opencode-server', restart: true),
        ];
    }
}
