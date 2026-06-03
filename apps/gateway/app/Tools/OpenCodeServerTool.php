<?php

declare(strict_types=1);

namespace App\Tools;

final class OpenCodeServerTool extends BaseTool
{
    private const string PROGRAM = 'orbit_tool_opencode_server';

    private const string LOG_PATH = '/var/log/orbit/orbit_tool_opencode_server.log';

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
        return ['install', 'remove', 'start', 'stop', 'restart', 'update', 'reconfigure', 'logs', 'credentials', 'safe-fix'];
    }

    public function installScript(array $config = []): string
    {
        $port = (int) ($config['port'] ?? 4096);
        $hostname = $config['hostname'] ?? '0.0.0.0';
        $username = $config['username'] ?? 'opencode';
        $password = $config['password'] ?? null;
        $authEnvironment = $this->authEnvironment($username, $password);
        $program = self::PROGRAM;
        $logPath = self::LOG_PATH;

        return <<<"BASH"
#!/usr/bin/env bash
# orbit install opencode-server
set -e
curl -fsSL https://opencode.ai/install | bash
user=$(whoami)
home=$(echo \$HOME)
program={$program}
logPath={$logPath}
configPath="/etc/supervisor/conf.d/\${program}.conf"
path="\${home}/.opencode/bin:\$(bash -lc 'echo \$PATH')"
sudo mkdir -p /etc/supervisor/conf.d
sudo install -d -m 0755 -o "\${user}" -g "\${user}" "\$(dirname "\${logPath}")"
sudo tee "\${configPath}" >/dev/null <<SUPERVISOR
[program:{$program}]
directory=\${home}
command=/bin/bash -lc 'exec "\${home}/.opencode/bin/opencode" serve --hostname {$hostname} --port {$port}'
user=\${user}
autostart=true
autorestart=unexpected
startsecs=0
redirect_stderr=true
stdout_logfile=\${logPath}
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
environment=HOME="\${home}",PATH="\${path}"{$authEnvironment}
SUPERVISOR
sudo supervisorctl reread
sudo supervisorctl update "\${program}"
sudo supervisorctl start "\${program}" >/dev/null 2>&1 || sudo supervisorctl restart "\${program}"
BASH;
    }

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove opencode-server
set -e
home=$(echo $HOME)
program=orbit_tool_opencode_server
sudo supervisorctl stop "${program}" >/dev/null 2>&1 || true
sudo supervisorctl remove "${program}" >/dev/null 2>&1 || true
sudo rm -f "/etc/supervisor/conf.d/${program}.conf"
sudo supervisorctl reread >/dev/null 2>&1 || true
sudo supervisorctl update >/dev/null 2>&1 || true
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
program=orbit_tool_opencode_server
"${home}/.opencode/bin/opencode" upgrade
sudo supervisorctl restart "${program}"
BASH;
    }

    public function credentialsScript(array $config = []): string
    {
        $hostname = $config['hostname'] ?? '0.0.0.0';
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
        $hostname = $config['hostname'] ?? '0.0.0.0';
        $username = $config['username'] ?? 'opencode';
        $password = $config['password'] ?? null;
        $authEnvironment = $this->authEnvironment($username, $password);
        $program = self::PROGRAM;
        $logPath = self::LOG_PATH;

        return <<<"BASH"
#!/usr/bin/env bash
# orbit reconfigure opencode-server
set -e
user=$(whoami)
home=$(echo \$HOME)
program={$program}
logPath={$logPath}
configPath="/etc/supervisor/conf.d/\${program}.conf"
path="\${home}/.opencode/bin:\$(bash -lc 'echo \$PATH')"
sudo mkdir -p /etc/supervisor/conf.d
sudo install -d -m 0755 -o "\${user}" -g "\${user}" "\$(dirname "\${logPath}")"
sudo tee "\${configPath}" >/dev/null <<SUPERVISOR
[program:{$program}]
directory=\${home}
command=/bin/bash -lc 'exec "\${home}/.opencode/bin/opencode" serve --hostname {$hostname} --port {$port}'
user=\${user}
autostart=true
autorestart=unexpected
startsecs=0
redirect_stderr=true
stdout_logfile=\${logPath}
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
environment=HOME="\${home}",PATH="\${path}"{$authEnvironment}
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
            'binary' => 'opencode',
            'supervisor_program' => self::PROGRAM,
            'supervisor_log' => self::LOG_PATH,
            'repair_commands' => $this->supervisorProgramRepairCommands(self::PROGRAM),
        ];
    }

    private function authEnvironment(mixed $username, mixed $password): string
    {
        if (! is_string($password) || $password === '') {
            return '';
        }

        $username = is_string($username) && $username !== '' ? $username : 'opencode';

        return ',OPENCODE_SERVER_USERNAME="'.$this->escapeSupervisorEnvironmentValue($username).'",OPENCODE_SERVER_PASSWORD="'.$this->escapeSupervisorEnvironmentValue($password).'"';
    }

    private function escapeSupervisorEnvironmentValue(string $value): string
    {
        return str_replace(
            ['\\', '"', '$', '`'],
            ['\\\\', '\"', '\$', '\`'],
            $value,
        );
    }
}
