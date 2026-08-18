<?php

declare(strict_types=1);

use App\Services\CodexApp\LocalCodexAppConfigStore;
use Illuminate\Filesystem\Filesystem;

it('releases the exclusive lock before closing the handle', function (): void {
    $home = sys_get_temp_dir().'/orbit-codex-app-store-'.bin2hex(random_bytes(8));
    $configPath = $home.'/.codex/codex-app/config.json';
    $store = new LocalCodexAppConfigStore(new Filesystem);

    try {
        $lock = $store->acquire($configPath);

        expect($lock)->toBeResource();

        $store->release($lock, $configPath);

        expect(is_resource($lock))->toBeFalse();

        $contender = fopen(filename: $configPath.'.lock', mode: 'c');

        expect($contender)
            ->toBeResource()
            ->and(flock($contender, LOCK_EX | LOCK_NB))
            ->toBeTrue();

        flock($contender, LOCK_UN);
        fclose($contender);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});
