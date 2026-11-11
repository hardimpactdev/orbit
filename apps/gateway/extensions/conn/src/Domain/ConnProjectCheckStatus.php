<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

enum ConnProjectCheckStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NotChecked = 'not_checked';
}
