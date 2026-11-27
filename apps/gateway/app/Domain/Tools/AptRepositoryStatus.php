<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/** A Node's signed apt repository: usable only once its own add succeeded (docs/reference/tools.md#apt-repositories). */
enum AptRepositoryStatus: string
{
    case Adding = 'adding';
    case Ready = 'ready';
    case Removing = 'removing';
    case Failed = 'failed';
}
