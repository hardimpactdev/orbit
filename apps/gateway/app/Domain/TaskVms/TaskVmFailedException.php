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
    public function __construct(public readonly TaskVm $vm)
    {
        parent::__construct(self::reason($vm->name, (string) $vm->error_code, (string) $vm->error_message));
    }

    /** The assistance reason of a group whose task VM failed, naming the VM and its error code. */
    public static function reason(string $name, string $code, string $message): string
    {
        return "Task VM failed: {$name}: {$code}: {$message} Cancel the group to destroy the VM.";
    }
}
