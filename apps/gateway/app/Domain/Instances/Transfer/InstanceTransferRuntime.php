<?php

declare(strict_types=1);

namespace App\Domain\Instances\Transfer;

use App\Infrastructure\Instances\NativeInstanceTransferRuntime;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeInstanceTransferRuntime::class)]
interface InstanceTransferRuntime
{
    public function pause(Instance $instance): void;

    public function restore(Instance $instance): void;

    public function relocate(
        Instance $instance,
        Node $destination,
        string $sourcePath,
        string $workingDirectory,
    ): void;

    public function activate(Instance $instance): void;

    public function cleanupSourceArtifacts(Instance $instance, Node $sourceNode, string $sourcePath): void;
}
