<?php

declare(strict_types=1);

namespace App\Tools;

final class PolyscopeServerTool extends BaseTool
{
    public function slug(): string
    {
        return 'polyscope-server';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'start', 'stop', 'restart', 'update', 'reconfigure', 'logs', 'safe-fix', 'safe-adopt'];
    }

    public function installScript(array $config = []): string
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

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove polyscope-server
set -e
home=$(echo $HOME)
export XDG_RUNTIME_DIR=/run/user/$(id -u)
systemctl --user stop polyscope-server 2>/dev/null || true
systemctl --user disable polyscope-server 2>/dev/null || true
rm -f "${home}/.config/systemd/user/polyscope-server.service"
systemctl --user daemon-reload
sudo systemctl stop polyscope-server 2>/dev/null || true
sudo systemctl disable polyscope-server 2>/dev/null || true
sudo rm -f /etc/systemd/system/polyscope-server.service
sudo systemctl daemon-reload
rm -f "${home}/.local/bin/polyscope-server"
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit update polyscope-server
set -e
home=$(echo $HOME)
export XDG_RUNTIME_DIR=/run/user/$(id -u)
"${home}/.local/bin/polyscope-server" update
systemctl --user restart polyscope-server
BASH;
    }

    public function reconfigureScript(array $config = []): string
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

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'polyscope-server',
            'service' => 'polyscope-server',
            'repair_commands' => $this->serviceRepairCommands('polyscope-server', restart: true),
        ];
    }
}
