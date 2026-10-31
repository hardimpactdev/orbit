<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;
use SensitiveParameter;

interface MetricsCredentialRuntime
{
    public function verify(Node $node, #[SensitiveParameter] string $password): bool;
}
