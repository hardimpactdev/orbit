<?php

declare(strict_types=1);

namespace App\Tools;

final class PolyscopeServerTool extends BaseTool
{
    private const string PROGRAM = 'orbit_tool_polyscope_server';

    private const string LOG_PATH = '/var/log/orbit/orbit_tool_polyscope_server.log';

    public function slug(): string
    {
        return 'polyscope-server';
    }

    #[\Override]
    public function category(): string
    {
        return 'development';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'start', 'stop', 'restart', 'update', 'reconfigure', 'logs', 'safe-fix', 'safe-adopt'];
    }

    public function installScript(array $config = []): string
    {
        $localTarget = $config['local_target'] ?? false;
        $program = self::PROGRAM;
        $logPath = self::LOG_PATH;
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
program={$program}
logPath={$logPath}
configPath="/etc/supervisor/conf.d/\${program}.conf"
path=$(bash -lc 'echo \$PATH')
userBin="\${home}/.local/bin"
if [[ ":\${path}:" != *":\${userBin}:"* ]]; then
  path="\${userBin}:\${path}"
fi
sudo mkdir -p /etc/supervisor/conf.d
sudo install -d -m 0755 -o "\${user}" -g "\${user}" "\$(dirname "\${logPath}")"
sudo tee "\${configPath}" >/dev/null <<SUPERVISOR
[program:{$program}]
directory=\${home}
command=/bin/bash -lc 'exec "\${home}/.local/bin/polyscope-server"'
user=\${user}
autostart=true
autorestart=unexpected
startsecs=0
redirect_stderr=true
stdout_logfile=\${logPath}
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
environment=HOME="\${home}",PATH="\${path}"
SUPERVISOR
sudo supervisorctl reread
sudo supervisorctl update "\${program}"
sudo supervisorctl start "\${program}" >/dev/null 2>&1 || sudo supervisorctl restart "\${program}"
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
program=orbit_tool_polyscope_server
sudo supervisorctl stop "${program}" >/dev/null 2>&1 || true
sudo supervisorctl remove "${program}" >/dev/null 2>&1 || true
sudo rm -f "/etc/supervisor/conf.d/${program}.conf"
sudo supervisorctl reread >/dev/null 2>&1 || true
sudo supervisorctl update >/dev/null 2>&1 || true
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
program=orbit_tool_polyscope_server
"${home}/.local/bin/polyscope-server" update
sudo supervisorctl restart "${program}"
BASH;
    }

    public function reconfigureScript(array $config = []): string
    {
        $program = self::PROGRAM;
        $logPath = self::LOG_PATH;

        return <<<"BASH"
#!/usr/bin/env bash
# orbit reconfigure polyscope-server
set -e
user=$(whoami)
home=$(echo \$HOME)
program={$program}
logPath={$logPath}
configPath="/etc/supervisor/conf.d/\${program}.conf"
path=\$(bash -lc 'echo \$PATH')
userBin="\${home}/.local/bin"
if [[ ":\${path}:" != *":\${userBin}:"* ]]; then
  path="\${userBin}:\${path}"
fi
sudo mkdir -p /etc/supervisor/conf.d
sudo install -d -m 0755 -o "\${user}" -g "\${user}" "\$(dirname "\${logPath}")"
sudo tee "\${configPath}" >/dev/null <<SUPERVISOR
[program:{$program}]
directory=\${home}
command=/bin/bash -lc 'exec "\${home}/.local/bin/polyscope-server"'
user=\${user}
autostart=true
autorestart=unexpected
startsecs=0
redirect_stderr=true
stdout_logfile=\${logPath}
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
environment=HOME="\${home}",PATH="\${path}"
SUPERVISOR
sudo supervisorctl reread
sudo supervisorctl update "\${program}"
sudo supervisorctl restart "\${program}"
BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'polyscope-server',
            'supervisor_program' => self::PROGRAM,
            'supervisor_log' => self::LOG_PATH,
            'repair_commands' => $this->supervisorProgramRepairCommands(self::PROGRAM),
        ];
    }
}
