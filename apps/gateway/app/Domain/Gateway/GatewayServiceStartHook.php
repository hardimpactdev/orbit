<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

/**
 * A plug-in point: a program that runs each time the Gateway PHP-FPM service starts ([Extensions](/reference/extensions#plug-in-points)).
 *
 * Gateway web convergence and the release handoff install the program as /etc/orbit/<name> with a `php8.5-fpm`
 * drop-in that runs it as root in `ExecStartPre`. Before either one reloads the pool, it runs each program, because a
 * reload skips `ExecStartPre`. A failed program stops the activation. Core records the hooks it installed and removes
 * one that is no longer contributed. The hook runs whether or not the extension is enabled.
 */
interface GatewayServiceStartHook
{
    /** The file name of the program in /etc/orbit. It ends in `-start`, which no core path there does. */
    public function name(): string;

    /** The file name of the `php8.5-fpm` drop-in that runs the program, `orbit-*.conf`. It need not share the program's stem. */
    public function dropIn(): string;

    /** One printable line that says what the program does, for the comment at the top of the drop-in. */
    public function description(): string;

    /** The bash program. */
    public function renderHook(string $checkoutPath, string $orbitHome): string;
}
