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
use InvalidArgumentException;

final readonly class SshRemoteShell implements RemoteShell, StartsRemoteShellProcesses
{
    private const int DEFAULT_TIMEOUT = 120;

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     env?: array<string, string>,
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
     *     env?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $pendingProcess = Process::timeout((int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT));

        if (array_key_exists('input', $options)) {
            $pendingProcess = $pendingProcess->input((string) $options['input']);
        }

        return $pendingProcess->start(
            $this->command($node, $this->composeScript($script, $options)),
        );
    }

    /**
     * @param  array{cwd?: string, env?: array<string, string>, strict?: bool}  $options
     */
    private function composeScript(string $script, array $options): string
    {
        if ((bool) ($options['strict'] ?? false)) {
            return $this->composeStrictScript($script, $options);
        }

        $prefix = '';

        if (isset($options['env']) && is_array($options['env'])) {
            $assignments = [];

            foreach ($options['env'] as $key => $value) {
                $assignments[] = $this->envAssignment((string) $key, (string) $value);
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

    /**
     * @param  array{cwd?: string, env?: array<string, string>}  $options
     */
    private function composeStrictScript(string $script, array $options): string
    {
        $lines = ['set -e'];

        if (isset($options['env']) && is_array($options['env'])) {
            foreach ($options['env'] as $key => $value) {
                $lines[] = 'export '.$this->envAssignment((string) $key, (string) $value);
            }
        }

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $lines[] = 'cd '.escapeshellarg($options['cwd']);
        }

        $lines[] = $script;

        return implode(PHP_EOL, $lines);
    }

    private function envAssignment(string $key, string $value): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid remote shell environment variable name [{$key}].");
        }

        return sprintf('%s=%s', $key, escapeshellarg($value));
    }

    private function command(Node $node, string $script): string
    {
        if ((bool) config('orbit.is_gateway', false) && app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
            return 'bash -c '.escapeshellarg($script);
        }

        $host = $this->host($node);
        $user = $node->user ?: 'orbit';

        return sprintf(
            'ssh -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR -o ConnectTimeout=10 -o ServerAliveInterval=30 -o ServerAliveCountMax=10 %s@%s %s',
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg('bash -lc '.escapeshellarg($script)),
        );
    }

    private function host(Node $node): string
    {
        if ($this->dockerTopologyMode() === 'dns-alias' && filled($node->host)) {
            return $node->host;
        }

        return $node->wireguard_address ?: $node->host;
    }

    private function dockerTopologyMode(): ?string
    {
        $processValue = getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');

        if (is_string($processValue) && $processValue !== '') {
            return $processValue;
        }

        $serverValue = $_SERVER['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] ?? null;

        if (is_string($serverValue) && $serverValue !== '') {
            return $serverValue;
        }

        $envValue = $_ENV['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] ?? null;

        return is_string($envValue) && $envValue !== '' ? $envValue : null;
    }
}
