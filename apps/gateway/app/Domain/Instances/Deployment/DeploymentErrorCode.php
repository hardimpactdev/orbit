<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\ProcessCancelledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final class DeploymentErrorCode
{
    public static function fromException(Throwable $exception): string
    {
        if ($exception instanceof ResourceOperationException && $exception->errorCode === 'command.deadline_exceeded') {
            return 'deployment.deadline_exceeded';
        }

        if ($exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException || $exception instanceof ProcessOperationException) {
            return $exception->errorCode;
        }

        if ($exception instanceof ProcessCancelledException) {
            return 'deployment.cancelled';
        }

        if ($exception instanceof ProcessTimedOutException) {
            return 'deployment.command_timed_out';
        }

        return 'deployment.interrupted';
    }
}
