<?php

declare(strict_types=1);

namespace App\Contracts;

interface CaddyfileGeneratorInterface
{
    /**
     * Generate the Caddyfile configuration.
     */
    public function generate(): void;

    /**
     * Reload Caddy to apply configuration changes.
     */
    public function reload(): bool;

    /**
     * Reload PHP-FPM processes.
     */
    public function reloadPhp(): bool;
}
