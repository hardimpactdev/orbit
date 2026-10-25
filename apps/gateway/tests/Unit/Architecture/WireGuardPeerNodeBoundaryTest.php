<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\WireGuard\NativeWireGuardPeerConverger;

arch('WireGuard peer convergence uses named agent commands')
    ->expect(NativeWireGuardPeerConverger::class)
    ->not->toUse([SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
