<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

enum DependencyReadFailure: string
{
    case Tls = 'tls';
    case Auth = 'auth';
    case Invalid = 'invalid';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
}
