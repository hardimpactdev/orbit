<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\TaskUnitProgram;
use App\Infrastructure\TaskVms\TaskVmTopologyEvidenceReader;
use App\Infrastructure\TaskVms\TaskVmTopologyUnit;

arch('Task topology execution and evidence use named agent commands')
    ->expect([TaskVmTopologyUnit::class, TaskVmTopologyEvidenceReader::class])
    ->not->toUse([DevelopmentSshExecutor::class, SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class, TaskUnitProgram::class]);
