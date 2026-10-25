<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Analytics\NativeAnalyticsClickhouseConfigurationManager;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;

arch('Analytics configuration uses named agent commands')
    ->expect(NativeAnalyticsClickhouseConfigurationManager::class)
    ->not->toUse([DevelopmentSshExecutor::class, SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
