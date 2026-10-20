<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use Illuminate\Container\Attributes\Bind;

#[Bind(LockRenewingProcessRunner::class)]
interface ProcessRunner
{
    public function run(ProcessInvocation $invocation): CommandResult;
}
