<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

enum ConnInstanceNameReason: string
{
    case Taken = 'taken';
    case Invalid = 'invalid';
    case Default = 'default';
    case DomainInvalid = 'domain_invalid';
}
