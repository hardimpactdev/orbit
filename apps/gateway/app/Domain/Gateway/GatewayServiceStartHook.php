<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

/**
 * A plug-in point: a program that runs each time the Gateway PHP-FPM service starts ([Extensions](/reference/extensions#plug-in-points)).
 *
 * Gateway web convergence installs the program in /etc/orbit with a `php8.5-fpm` drop-in that runs it as root in
 * `ExecStartPre`. It also runs the program before it activates the pool, because a reload skips `ExecStartPre`. A
 * failed program stops the activation. The hook runs whether or not the extension is enabled.
 */
interface GatewayServiceStartHook
{
    /** The file name of the program in /etc/orbit. */
    public function name(): string;

    /** The file name of the `php8.5-fpm` drop-in that runs the program. */
    public function dropIn(): string;

    /** What the program does, for the comment at the top of the drop-in. */
    public function description(): string;

    /** The bash program. */
    public function renderHook(string $checkoutPath, string $orbitHome): string;
}
