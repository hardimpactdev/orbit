<?php

declare(strict_types=1);

namespace App\Enums\WebSockets;

enum CurrentImageVerification: string
{
    case Matches = 'matches';
    case Differs = 'differs';
    case CouldNotVerify = 'could_not_verify';

    public function requiresRecreation(): bool
    {
        return $this === self::Differs;
    }
}
