<?php

use App\Services\Install\InstallLogger;
use LaravelZero\Framework\Commands\Command;

function createTestLogger(): InstallLogger
{
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('newLine')->andReturnNull();
    $command->shouldReceive('line')->andReturnNull();
    $command->shouldReceive('warn')->andReturnNull();
    $command->shouldReceive('skip')->andReturnNull();
    $command->shouldReceive('success')->andReturnNull();

    return new InstallLogger($command);
}
