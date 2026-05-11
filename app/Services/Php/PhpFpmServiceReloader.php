<?php

declare(strict_types=1);

namespace App\Services\Php;

final readonly class PhpFpmServiceReloader
{
    public function reloadOrRestartScript(string $service): string
    {
        return sprintf(
            <<<'SH'
PHP_FPM_SERVICE=%s
if sudo systemctl is-active --quiet "$PHP_FPM_SERVICE"; then
    sudo systemctl reload "$PHP_FPM_SERVICE"
elif sudo systemctl list-unit-files "${PHP_FPM_SERVICE}.service" 2>/dev/null | grep -F -q "${PHP_FPM_SERVICE}.service"; then
    sudo systemctl restart "$PHP_FPM_SERVICE"
elif sudo systemctl is-active --quiet php-fpm; then
    sudo systemctl reload php-fpm
else
    sudo systemctl restart php-fpm
fi
SH,
            escapeshellarg($service),
        );
    }
}
