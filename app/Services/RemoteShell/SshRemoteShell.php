<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use Illuminate\Support\Facades\Process;

final readonly class SshRemoteShell implements RemoteShell
{
    private const int DEFAULT_TIMEOUT = 120;

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     env?: array<string, string>,
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

        if ((bool) ($options['throw'] ?? false) && ! $result->successful()) {
            throw new RemoteShellFailed($node, $composedScript, $result);
        }

        return $result;
    }

    /**
     * @param  array{cwd?: string, env?: array<string, string>}  $options
     */
    private function composeScript(string $script, array $options): string
    {
        $prefix = '';

        if (isset($options['env']) && is_array($options['env'])) {
            $assignments = [];

            foreach ($options['env'] as $key => $value) {
                $assignments[] = sprintf('%s=%s', $key, escapeshellarg($value));
            }

            if ($assignments !== []) {
                $prefix .= 'export '.implode(' ', $assignments).' && ';
            }
        }

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $prefix .= 'cd '.escapeshellarg($options['cwd']).' && ';
        }

        return $prefix.$script;
    }

    private function command(Node $node, string $script): string
    {
        if ($node->is_local) {
            return 'bash -c '.escapeshellarg($script);
        }

        $host = $node->wireguard_address ?: $node->host;
        $user = $node->ssh_user ?: 'orbit';

        return sprintf(
            'ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 -o ServerAliveInterval=30 -o ServerAliveCountMax=10 %s@%s %s',
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg('bash -lc '.escapeshellarg($script)),
        );
    }
}
