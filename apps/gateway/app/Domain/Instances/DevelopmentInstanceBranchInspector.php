<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteDevelopmentInstanceSourceLifecycle::class)]
interface DevelopmentInstanceBranchInspector
{
    public function assertBranchCheckedOut(Instance $instance, ?string $branch): void;
}
