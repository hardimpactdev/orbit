<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

final readonly class RemoteHostExecutor implements RemoteExecutor
{
    private const int DEFAULT_TIMEOUT = 120;

    public function __construct(
        private RemoteShellScriptComposer $scripts,
        private SshCommandBuilder $ssh,
        private NodeRoleAssignments $roleAssignments,
        private RemoteShellAuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    #[\Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $composedScript = $this->scripts->compose($script, $options);
        $command = $this->command($node, $composedScript);

        $startedAt = hrtime(true);
        $processResult = $this->pendingProcess($options)->run($command);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $result = new RemoteShellResult(
            exitCode: $processResult->exitCode() ?? 1,
            stdout: $processResult->output(),
            stderr: $processResult->errorOutput(),
            durationMs: $durationMs,
        );

        $this->auditLogger->log('remote_shell.run', $node, $script, $options, $result);

        if ((bool) ($options['throw'] ?? false) && ! $result->successful()) {
            throw new RemoteShellFailed($node, $composedScript, $result);
        }

        return $result;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    #[\Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $process = $this->pendingProcess($options)->start(
            $this->command($node, $this->scripts->compose($script, $options)),
        );

        $this->auditLogger->log('remote_shell.start', $node, $script, $options);

        return $process;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    private function pendingProcess(array $options): PendingProcess
    {
        $pendingProcess = Process::timeout((int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT));

        if (array_key_exists('input', $options)) {
            return $pendingProcess->input((string) $options['input']);
        }

        return $pendingProcess;
    }

    private function command(Node $node, string $script): string
    {
        if ($this->roleAssignments->nodeIsGateway($node) && ! $this->runningInsideOrbitGateway()) {
            return 'bash -c '.escapeshellarg($script);
        }

        return $this->ssh->enforceForNode(
            node: $node,
            remoteCommand: 'bash -lc '.escapeshellarg($script),
            options: [
                'log_level' => 'ERROR',
                'server_alive_interval' => 30,
                'server_alive_count_max' => 10,
            ],
        );
    }

    private function runningInsideOrbitGateway(): bool
    {
        $hostPath = getenv('ORBIT_HOST_PATH');

        if (is_string($hostPath) && trim($hostPath) !== '') {
            return true;
        }

        $sourcePath = getenv('ORBIT_SOURCE_PATH');

        return is_string($sourcePath) && trim($sourcePath) === '/opt/orbit';
    }
}
