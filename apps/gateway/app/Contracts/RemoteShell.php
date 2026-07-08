<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;

interface RemoteShell
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
    public function run(Node $node, string $script, array $options = []): RemoteShellResult;
}
