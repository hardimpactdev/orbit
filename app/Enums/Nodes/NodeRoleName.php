<?php

declare(strict_types=1);

namespace App\Enums\Nodes;

enum NodeRoleName: string
{
    case Gateway = 'gateway';
    case AppDevelopment = 'app-development';
    case AppProduction = 'app-production';
    case Database = 'database';
    case Agent = 'agent';
}
