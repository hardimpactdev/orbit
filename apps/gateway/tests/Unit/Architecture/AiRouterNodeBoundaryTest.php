<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;

arch('AI router Node behavior runs only through named agent commands')
    ->expect('Orbit\\Extensions\\AiRouter\\Infrastructure')
    ->not->toUse([SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
