<?php

declare(strict_types=1);

use App\Actions\Nodes\RetargetNodeAction;
use App\Infrastructure\Gateway\NativeGatewaySelfAccessConverger;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;

arch('Gateway self-access leaves host execution to the agent runner')
    ->expect(NativeGatewaySelfAccessConverger::class)
    ->not->toUse([ProcessRunner::class, SshExecutor::class]);

arch('Node retarget leaves connectivity probes to the agent runner')
    ->expect(RetargetNodeAction::class)
    ->not->toUse([ProcessRunner::class, SshExecutor::class]);
