<?php

declare(strict_types=1);

return (static function (): array {
    $configRoot = getenv('ORBIT_CONFIG_ROOT');

    if (! is_string($configRoot) || trim($configRoot) === '') {
        $home = getenv('HOME');

        if (! is_string($home) || trim($home) === '') {
            $home = '/home/orbit';
        }

        $configRoot = rtrim($home, '/').'/.config/orbit';
    }

    $configRoot = rtrim($configRoot, '/');
    $orbitGatewayRoot = $configRoot.'/gateway';

    return [
        'config_root' => $configRoot,
        'gateway_root' => $orbitGatewayRoot,
        'env_path' => $orbitGatewayRoot.'/.env',
        'database_path' => $orbitGatewayRoot.'/database',
        'storage_path' => $orbitGatewayRoot.'/storage',
    ];
})();
