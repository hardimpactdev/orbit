<?php

declare(strict_types=1);

namespace App\Enums\Apps;

enum DependencyAuditManager: string
{
    case Composer = 'composer';
    case Npm = 'npm';
    case Bun = 'bun';
}
