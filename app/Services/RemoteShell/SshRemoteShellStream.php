<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\RemoteShellStream;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Facades\Process;

final readonly class SshRemoteShellStream implements RemoteShellStream
{
    /**
     * @param  callable(string): void  $onOutput
     * @param  array{
     *      cwd?: string,
     *      timeout?: int|null,
     *      env?: array<string, string>,
     *  }  $options
     */
    public function stream(Node $node, string $script, callable $onOutput, array $options = []): int
    {
        $pendingProcess = array_key_exists('timeout', $options) && $options['timeout'] !== null
            ? Process::timeout((int) $options['timeout'])
            : Process::forever();

        $result = $pendingProcess->run(
            $this->command($node, $this->composeScript($script, $options)),
            function (string $type, string $output) use ($onOutput): void {
                if ($type === 'out') {
                    $onOutput($output);
                }
            },
        );

        return $result->exitCode() ?? 1;
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
        if ((bool) config('orbit.is_gateway', false) && app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
            return 'bash -c '.escapeshellarg($script);
        }

        $host = $node->wireguard_address ?: $node->host;
        $user = $node->user ?: 'orbit';

        return sprintf(
            'ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 -o ServerAliveInterval=30 -o ServerAliveCountMax=10 %s@%s %s',
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg('bash -lc '.escapeshellarg($script)),
        );
    }
}
