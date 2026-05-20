<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

final readonly class SshRemoteShell implements RemoteShell, StartsRemoteShellProcesses
{
    private const int DEFAULT_TIMEOUT = 120;

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
        $composedScript = $this->composeScript($script, $options);
        $command = $this->command($node, $composedScript);
        $timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $input = array_key_exists('input', $options) ? (string) $options['input'] : null;

        $pendingProcess = Process::timeout($timeout);

        if ($input !== null) {
            $pendingProcess = $pendingProcess->input($input);
        }

        $startedAt = hrtime(true);
        $processResult = $pendingProcess->run($command);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $result = new RemoteShellResult(
            exitCode: $processResult->exitCode() ?? 1,
            stdout: $processResult->output(),
            stderr: $processResult->errorOutput(),
            durationMs: $durationMs,
        );

        app(RemoteShellAuditLogger::class)->log('remote_shell.run', $node, $script, $options, $result);

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
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $pendingProcess = Process::timeout((int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT));

        if (array_key_exists('input', $options)) {
            $pendingProcess = $pendingProcess->input((string) $options['input']);
        }

        $process = $pendingProcess->start(
            $this->command($node, $this->composeScript($script, $options)),
        );

        app(RemoteShellAuditLogger::class)->log('remote_shell.start', $node, $script, $options);

        return $process;
    }

    /**
     * @param  array{cwd?: string, metadata?: array<string, string>, strict?: bool}  $options
     */
    private function composeScript(string $script, array $options): string
    {
        if ((bool) ($options['strict'] ?? false)) {
            return $this->composeStrictScript($script, $options);
        }

        $prefix = '';

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $prefix .= app(RemoteShellMetadata::class)->prologue($this->stringMap($options['metadata']));
        }

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $prefix .= 'cd '.escapeshellarg($options['cwd']).' && ';
        }

        return $prefix.$script;
    }

    /**
     * @param  array{cwd?: string, metadata?: array<string, string>}  $options
     */
    private function composeStrictScript(string $script, array $options): string
    {
        $lines = ['set -e'];

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $prologue = app(RemoteShellMetadata::class)->prologue($this->stringMap($options['metadata']));

            foreach (array_filter(explode('; ', trim($prologue))) as $line) {
                $lines[] = rtrim($line, ';');
            }
        }

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $lines[] = 'cd '.escapeshellarg($options['cwd']);
        }

        $lines[] = $script;

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<mixed>  $metadata
     * @return array<string, string>
     */
    private function stringMap(array $metadata): array
    {
        $resolved = [];

        foreach ($metadata as $key => $value) {
            $resolved[(string) $key] = (string) $value;
        }

        return $resolved;
    }

    private function command(Node $node, string $script): string
    {
        if ((bool) config('orbit.is_gateway', false) && app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
            return 'bash -c '.escapeshellarg($script);
        }

        return app(SshCommandBuilder::class)->enforceForNode(
            node: $node,
            remoteCommand: 'bash -lc '.escapeshellarg($script),
            options: [
                'log_level' => 'ERROR',
                'server_alive_interval' => 30,
                'server_alive_count_max' => 10,
            ],
        );
    }
}
