<?php

declare(strict_types=1);

namespace App\Exceptions;

enum GatewayApiFailureKind
{
    case Http;
    case Network;
    case WireguardUnreachable;
    case StreamClosedBeforeTerminal;
    case StreamMalformed;
    case Generic;

    public function cliFailureCode(): string
    {
        return match ($this) {
            self::WireguardUnreachable => 'gateway_unreachable_wireguard',
            default => 'gateway_unavailable',
        };
    }
}
