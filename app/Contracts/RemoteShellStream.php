<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Node;

interface RemoteShellStream
{
    /**
     * @param  callable(string): void  $onOutput
     * @param  array{
     *      cwd?: string,
     *      timeout?: int|null,
     *      env?: array<string, string>,
     *  }  $options
     */
    public function stream(Node $node, string $script, callable $onOutput, array $options = []): int;
}
