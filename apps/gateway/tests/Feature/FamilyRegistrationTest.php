<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;

it('discovers a new family route file without changing the route loader', function (): void {
    $root = sys_get_temp_dir().'/orbit-family-'.bin2hex(random_bytes(8));
    mkdir($root.'/api', 0777, true);
    try {
        copy(base_path('routes/api.php'), $root.'/api.php');
        file_put_contents($root.'/api/probe.php', <<<'ROUTE'
        <?php
        Illuminate\Support\Facades\Route::get('family-probe', fn (): string => 'ready')->name('family-probe');
        ROUTE);
        require $root.'/api.php';
        Route::getRoutes()->refreshNameLookups();

        expect(Route::getRoutes()->getByName('family-probe')?->uri())->toBe('v1/family-probe');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('discovers a new domain provider without changing the provider list', function (): void {
    $root = sys_get_temp_dir().'/orbit-provider-'.bin2hex(random_bytes(8));
    mkdir($root.'/bootstrap', 0777, true);
    mkdir($root.'/app/Providers/Domains', 0777, true);
    try {
        copy(base_path('bootstrap/providers.php'), $root.'/bootstrap/providers.php');
        file_put_contents($root.'/app/Providers/Domains/ProbeServiceProvider.php', '<?php');

        expect(require $root.'/bootstrap/providers.php')->toContain('App\\Providers\\Domains\\ProbeServiceProvider');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});
