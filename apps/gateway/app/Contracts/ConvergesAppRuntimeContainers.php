<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainer;

interface ConvergesAppRuntimeContainers
{
    public function apply(Node $node, AppRuntimeContainer $container): AppRuntimeContainerApplyOutcome;
}
