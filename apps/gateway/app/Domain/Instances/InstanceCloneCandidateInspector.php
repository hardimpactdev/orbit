<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Instances\RemoteInstanceCloneCandidateInspector;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteInstanceCloneCandidateInspector::class)]
interface InstanceCloneCandidateInspector
{
    public function inspect(Instance $candidate, string $targetBranch): CloneCandidateSource;
}
