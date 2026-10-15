<?php

declare(strict_types=1);

namespace App\Domain\RemotePrograms;

enum RemoteProgramLanguage: string
{
    case Python = 'py';
    case Bash = 'sh';
}
