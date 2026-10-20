<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Infrastructure\Doctor\SshNodeStateInspector;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(SshNodeStateInspector::class)]
interface NodeStateInspector
{
    public function inspect(Node $node): NodeInspectionData;
}
