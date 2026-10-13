<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;
use RuntimeException;

/**
 * The group's task VM is `failed`. This is not a wait: the claim asks for assistance with this message, and the
 * group gets no other VM until it is cancelled, which destroys the VM.
 */
final class TaskVmFailedException extends RuntimeException
{
    /** The start of the assistance reason of every group whose task VM failed. */
    public const string ReasonPrefix = 'Task VM failed: ';

    public function __construct(public readonly TaskVm $vm)
    {
        parent::__construct(self::reason($vm->name, (string) $vm->error_code, (string) $vm->error_message));
    }

    /** The assistance reason of a group whose task VM failed, naming the VM and its error code. */
    public static function reason(string $name, string $code, string $message): string
    {
        return self::ReasonPrefix."{$name}: {$code}: {$message} Cancel the group to destroy the VM.";
    }

    /** The assistance reason of a group whose `ready` or `parked` task VM failed: a resume or the sweep failed it. */
    public static function stoppedReason(string $code, string $message): string
    {
        return self::ReasonPrefix."{$code}: {$message} Cancel or complete the group to destroy the VM.";
    }
}
