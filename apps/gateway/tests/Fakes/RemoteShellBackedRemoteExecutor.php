<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use RuntimeException;

final readonly class RemoteShellBackedRemoteExecutor implements RemoteExecutor
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return app(RemoteShell::class)->run($node, $script, $options);
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('The test remote shell executor does not start processes.');
    }
}
