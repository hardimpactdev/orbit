<?php

declare(strict_types=1);

namespace App\Http\Authorization;

enum ServingNode
{
    case Gateway;
    case Target;
    case AppOwning;
    case AppInstanceOwning;
    case WorkspaceOwning;
    case Caller;
}
