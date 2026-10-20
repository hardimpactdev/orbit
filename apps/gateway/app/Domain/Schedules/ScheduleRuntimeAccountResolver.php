<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Infrastructure\Schedules\SshScheduleRuntimeAccountResolver;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(SshScheduleRuntimeAccountResolver::class)]
interface ScheduleRuntimeAccountResolver
{
    public function resolve(Node $node, string $user): ScheduleRuntimeAccount;
}
