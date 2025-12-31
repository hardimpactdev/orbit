<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Process;

class SshService
{
    protected string $controlDir;
    protected int $controlPersist = 600;

    public function __construct()
    {
        // Use /tmp for control sockets to avoid path length issues
        $this->controlDir = '/tmp/launchpad-ssh';

        if (!is_dir($this->controlDir)) {
            mkdir($this->controlDir, 0700, true);
        }
    }

    protected function getControlPath(Server $server): string
    {
        // Use short hash to avoid path length limits on macOS
        $hash = substr(md5("{$server->user}@{$server->host}:{$server->port}"), 0, 12);
        return "{$this->controlDir}/ctrl-{$hash}";
    }

    public function testConnection(Server $server): array
    {
        if ($server->is_local) {
            return [
                'success' => true,
                'message' => 'Local connection',
            ];
        }

        $result = Process::timeout(10)->run($this->buildSshCommand($server, 'echo "connected"'));

        return [
            'success' => $result->successful(),
            'message' => $result->successful() ? 'Connected successfully' : $result->errorOutput(),
            'output' => $result->output(),
        ];
    }

    public function execute(Server $server, string $command): array
    {
        if ($server->is_local) {
            $result = Process::timeout(30)->run($command);
        } else {
            // Prepend common paths for PHP and other binaries
            $pathPrefix = 'export PATH="$HOME/.config/herd-lite/bin:$HOME/.local/bin:$HOME/bin:/usr/local/bin:$PATH" && ';
            $result = Process::timeout(30)->run($this->buildSshCommand($server, $pathPrefix . $command));
        }

        return [
            'success' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error' => $result->errorOutput(),
        ];
    }

    public function executeJson(Server $server, string $command): array
    {
        $result = $this->execute($server, $command);

        if (!$result['success']) {
            return $result;
        }

        $decoded = json_decode($result['output'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
                'error' => 'Failed to parse JSON: ' . json_last_error_msg(),
            ];
        }

        return [
            'success' => true,
            'exit_code' => $result['exit_code'],
            'data' => $decoded,
        ];
    }

    protected function buildSshCommand(Server $server, string $command): string
    {
        $controlPath = $this->getControlPath($server);

        $sshOptions = [
            '-o BatchMode=yes',
            '-o StrictHostKeyChecking=accept-new',
            '-o ConnectTimeout=10',
            "-o ControlPath={$controlPath}",
            '-o ControlMaster=auto',
            "-o ControlPersist={$this->controlPersist}",
        ];

        if ($server->port !== 22) {
            $sshOptions[] = "-p {$server->port}";
        }

        $options = implode(' ', $sshOptions);
        $escapedCommand = escapeshellarg($command);

        return "ssh {$options} {$server->user}@{$server->host} {$escapedCommand}";
    }

    public function closeConnection(Server $server): void
    {
        if ($server->is_local) {
            return;
        }

        $controlPath = $this->getControlPath($server);
        Process::run("ssh -O exit -o ControlPath={$controlPath} {$server->user}@{$server->host}");
    }
}
