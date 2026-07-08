<?php

declare(strict_types=1);

namespace App\Data\RemoteShell;

use App\Models\Node;

final readonly class RemoteShellPoolJob
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
    public function __construct(
        public string $key,
        public Node $node,
        public string $script,
        public array $options = [],
    ) {}
}
