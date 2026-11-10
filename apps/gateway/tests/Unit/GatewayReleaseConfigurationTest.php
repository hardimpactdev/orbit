<?php

declare(strict_types=1);

use Illuminate\Support\Env;

it('keeps two recent releases by default and accepts an explicit limit', function (?string $value, int $expected): void {
    $environment = Env::getRepository();
    $previous = $environment->get('ORBIT_GATEWAY_RELEASES_KEEP');

    try {
        $value === null ? $environment->clear('ORBIT_GATEWAY_RELEASES_KEEP') : $environment->set('ORBIT_GATEWAY_RELEASES_KEEP', $value);

        expect((require config_path('orbit.php'))['gateway_releases']['keep'])->toBe($expected);
    } finally {
        $previous === null ? $environment->clear('ORBIT_GATEWAY_RELEASES_KEEP') : $environment->set('ORBIT_GATEWAY_RELEASES_KEEP', $previous);
    }
})->with(['default' => [null, 2], 'configured' => ['4', 4]]);
