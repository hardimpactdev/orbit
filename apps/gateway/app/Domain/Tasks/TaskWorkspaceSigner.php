<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

/**
 * Commits an approved subtask in a Task group's shared checkout.
 */
#[Bind(RemoteTaskWorkspaceSigner::class)]
interface TaskWorkspaceSigner
{
    public function commit(Instance $instance, string $message): ?string;
}
