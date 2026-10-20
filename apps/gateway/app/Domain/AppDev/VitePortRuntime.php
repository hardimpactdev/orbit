<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Infrastructure\AppDev\RemoteVitePortRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteVitePortRuntime::class)]
interface VitePortRuntime
{
    /** @param list<int> $excluded */
    public function selectPort(Node $node, int $preferred, array $excluded): int;

    public function ownsListener(Process $process, Instance $instance, int $port): bool;

    public function ready(Process $process, Instance $instance, int $port): bool;

    public function suspendTraffic(Instance $instance): void;

    public function markAwake(Instance $instance): void;

    public function prepare(Process $process, Instance $instance): void;

    public function project(Instance $instance): void;
}
