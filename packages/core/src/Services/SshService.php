<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Environment;
use Illuminate\Support\Facades\Process;

class SshService
{
    // Use /tmp for control sockets to avoid path length issues
    protected string $controlDir = '/tmp/orbit-ssh';

    protected int $controlPersist = 600;

    public function __construct()
    {
        if (! is_dir($this->controlDir)) {
            mkdir($this->controlDir, 0700, true);
        }
    }

    protected function getControlPath(Environment $environment): string
    {
        // Use short hash to avoid path length limits on macOS
        $hash = substr(md5("{$environment->user}@{$environment->host}:{$environment->port}"), 0, 12);

        return "{$this->controlDir}/ctrl-{$hash}";
    }

    public function testConnection(Environment $environment): array
    {
        if ($environment->is_local) {
            return [
                'success' => true,
                'message' => 'Local connection',
            ];
        }

        $result = Process::timeout(10)->run($this->buildSshCommand($environment, 'echo "connected"'));

        return [
            'success' => $result->successful(),
            'message' => $result->successful() ? 'Connected successfully' : $result->errorOutput(),
            'output' => $result->output(),
        ];
    }

    public function execute(Environment $environment, string $command, int $timeout = 30): array
    {
        if ($environment->is_local) {
            $result = Process::timeout($timeout)->run($command);
        } else {
            // Prepend common paths for PHP and other binaries
            $pathPrefix = 'export PATH="$HOME/.local/bin:$HOME/.bun/bin:$HOME/bin:/usr/local/bin:$PATH" && ';
            $result = Process::timeout($timeout)->run($this->buildSshCommand($environment, $pathPrefix.$command));
        }

        return [
            'success' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error' => $result->errorOutput(),
        ];
    }

    public function executeJson(Environment $environment, string $command): array
    {
        $result = $this->execute($environment, $command);

        // Always try to parse JSON from stdout, even if command failed
        // CLI tools often return valid JSON with error info even on non-zero exit
        $decoded = json_decode((string) $result['output'], true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Successfully parsed JSON - return the decoded data
            // The CLI's own success/error fields will be in $decoded
            return [
                'success' => true,
                'exit_code' => $result['exit_code'],
                'data' => $decoded,
            ];
        }

        // JSON parsing failed - return the raw result
        if (! $result['success']) {
            return $result;
        }

        return [
            'success' => false,
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
            'error' => 'Failed to parse JSON: '.json_last_error_msg(),
        ];
    }

    protected function buildSshCommand(Environment $environment, string $command): string
    {
        $controlPath = $this->getControlPath($environment);

        $sshOptions = [
            '-o BatchMode=yes',
            '-o StrictHostKeyChecking=accept-new',
            '-o ConnectTimeout=10',
            "-o ControlPath={$controlPath}",
            '-o ControlMaster=auto',
            "-o ControlPersist={$this->controlPersist}",
        ];

        if ($environment->port !== 22) {
            $sshOptions[] = "-p {$environment->port}";
        }

        $options = implode(' ', $sshOptions);
        $escapedCommand = escapeshellarg($command);

        return "ssh {$options} {$environment->user}@{$environment->host} {$escapedCommand}";
    }

    public function closeConnection(Environment $environment): void
    {
        if ($environment->is_local) {
            return;
        }

        $controlPath = $this->getControlPath($environment);
        Process::run("ssh -O exit -o ControlPath={$controlPath} {$environment->user}@{$environment->host}");
    }
}
