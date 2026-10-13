<?php

declare(strict_types=1);

namespace App\E2E;

use RuntimeException;

/**
 * The Gateway `.env` of a checkout. `bin/bootstrap` writes it from `.env.example`; a task workspace after
 * `composer install` has none, so the harness writes it the same way before it renders programs or acquires.
 */
final class GatewayEnvironment
{
    public static function ensure(string $checkout): void
    {
        $env = $checkout.'/apps/gateway/.env';
        if (! file_exists($env) && is_file($env.'.example') && ! copy($env.'.example', $env)) {
            throw new RuntimeException('Unable to write apps/gateway/.env from .env.example.');
        }
    }
}
