<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Contracts\Process\InvokedProcess;

final readonly class SshRemoteShell implements RemoteShell, StartsRemoteShellProcesses
{
    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     force_remote_host?: bool,
     * }  $options
     */
    #[\Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        // @orbit-ssh-lane provisioning-ssh
        return app(RemoteHostExecutor::class)->run($node, $script, $options);
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     force_remote_host?: bool,
     * }  $options
     */
    #[\Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        // @orbit-ssh-lane provisioning-ssh
        return app(RemoteHostExecutor::class)->start($node, $script, $options);
    }
}
