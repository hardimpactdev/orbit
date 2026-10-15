<?php

declare(strict_types=1);

namespace App\Domain\RemotePrograms;

use RuntimeException;
use Throwable;

/**
 * A program run that ended without a declared outcome. `$log` holds the program's standard error, and `$exitCode` its
 * exit status when it ended with one.
 */
final class RemoteProgramFailed extends RuntimeException
{
    public function __construct(
        public readonly string $program,
        public readonly RemoteProgramFailure $reason,
        public readonly string $log = '',
        ?Throwable $previous = null,
        public readonly ?int $exitCode = null,
    ) {
        parent::__construct("Remote program [{$program}] failed: {$reason->value}.", previous: $previous);
    }
}
