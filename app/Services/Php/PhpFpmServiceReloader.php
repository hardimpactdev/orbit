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
PHP_FPM_VERSION="${PHP_FPM_SERVICE#php}"
PHP_FPM_VERSION="${PHP_FPM_VERSION%%-fpm}"
for ORBIT_TARGET_POOL in "/etc/php/${PHP_FPM_VERSION}/fpm/pool.d"/orbit-*.conf; do
    [ -e "$ORBIT_TARGET_POOL" ] || continue
    ORBIT_POOL_FILE="$(basename "$ORBIT_TARGET_POOL")"

    for ORBIT_STALE_POOL in /etc/php/*/fpm/pool.d/"$ORBIT_POOL_FILE"; do
        [ -e "$ORBIT_STALE_POOL" ] || continue
        [ "$ORBIT_STALE_POOL" = "$ORBIT_TARGET_POOL" ] && continue

        ORBIT_STALE_VERSION="$(printf '%%s' "$ORBIT_STALE_POOL" | sed -E 's#^/etc/php/([^/]+)/fpm/pool.d/.*$#\1#')"
        ORBIT_STALE_SERVICE="php${ORBIT_STALE_VERSION}-fpm"
        sudo rm -f "$ORBIT_STALE_POOL"

        if sudo systemctl is-active --quiet "$ORBIT_STALE_SERVICE"; then
            sudo systemctl reload "$ORBIT_STALE_SERVICE" || sudo systemctl restart "$ORBIT_STALE_SERVICE"
        fi
    done
done

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
