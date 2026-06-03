<?php

declare(strict_types=1);

use App\E2E\Support\E2ECurrentCheckout;

it('hydrates reused vendor dependencies inside the current checkout instead of symlinking to the base checkout', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'reusePreparedVendorWithLocalAutoloadCommand');

    $command = $method->invoke(
        null,
        'apps/gateway',
        '/home/orbit/orbit-current-base-1234567890',
        "else echo 'missing vendor'; exit 127",
    );

    expect($command)
        ->toContain('cp -al "$path" "$target"/')
        ->toContain('cp -a --reflink=always "$path" "$target"/')
        ->not->toContain('ln -s');
});
