<?php

require __DIR__.'/vendor/autoload.php';

use App\Services\ServiceManager;

try {
    echo "Creating ServiceManager...\n";
    $manager = new ServiceManager;

    echo "ServiceManager created successfully!\n";
    echo 'Services loaded: '.count($manager->getServices())."\n";

    foreach ($manager->getServices() as $name => $config) {
        $enabled = $config['enabled'] ?? false;
        $status = $enabled ? 'enabled' : 'disabled';
        echo "  - {$name}: {$status}\n";
    }

    exit(0);
} catch (\Exception $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
    echo 'Trace: '.$e->getTraceAsString()."\n";
    exit(1);
}
