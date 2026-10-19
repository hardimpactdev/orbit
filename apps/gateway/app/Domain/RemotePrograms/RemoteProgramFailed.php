<?php

declare(strict_types=1);

namespace App\Domain\RemotePrograms;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use RuntimeException;
use Throwable;

/**
 * A program run that ended without a declared outcome. `$log` holds the program's standard error, and `$exitCode` its
 * exit status when it ended with one.
 */
final class RemoteProgramFailed extends RuntimeException
{
    public readonly string $log;

    public function __construct(
        public readonly string $program,
        public readonly RemoteProgramFailure $reason,
        #[\SensitiveParameter] string $log = '',
        ?Throwable $previous = null,
        public readonly ?int $exitCode = null,
    ) {
        $sanitized = new CommandActivityInputSanitizer()->redactText(mb_scrub($log, 'UTF-8'));
        $sanitized = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $sanitized) ?? '';
        $this->log = mb_strcut(trim($sanitized), -4096, encoding: 'UTF-8');
        $status = $exitCode === null ? '' : " Remote exit {$exitCode}.";
        $detail = $this->log === '' ? '' : " Stderr tail:\n{$this->log}";

        parent::__construct("Remote program [{$program}] failed: {$reason->value}.{$status}{$detail}", previous: $previous);
    }
}
