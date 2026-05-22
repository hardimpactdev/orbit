<?php

declare(strict_types=1);

namespace App\Services\Apps;

enum AppRuntimeContainerApplyOutcome: string
{
    case Created = 'created';
    case Recreated = 'recreated';
    case Started = 'started';
    case Unchanged = 'unchanged';
}
