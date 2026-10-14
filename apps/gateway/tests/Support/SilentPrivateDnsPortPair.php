<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Socket;

final class SilentPrivateDnsPortPair
{
    /** @return array{Socket, Socket, int} */
    public static function hold(string $address): array
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $udp = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (! $udp instanceof Socket) {
                throw new RuntimeException('Could not create a silent UDP socket.');
            }

            // PHP stream servers enable SO_REUSEADDR, allowing an ephemeral UDP port to be shared.
            if (! socket_bind($udp, $address, 0) || ! socket_getsockname($udp, $host, $port)) {
                socket_close($udp);

                throw new RuntimeException('Could not bind a silent UDP socket.');
            }

            $tcp = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (! $tcp instanceof Socket) {
                socket_close($udp);

                throw new RuntimeException('Could not create a silent TCP socket.');
            }

            // Reserve TCP without listening: fallback must be refused, not reach another test's server.
            if (@socket_bind($tcp, $address, $port)) {
                return [$udp, $tcp, $port];
            }

            socket_close($tcp);
            socket_close($udp);
        }

        throw new RuntimeException('Could not hold a silent UDP and TCP port pair.');
    }
}
