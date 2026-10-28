<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskOrchestration: string
{
    case External = 'external';
}
