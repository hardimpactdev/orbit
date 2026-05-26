<?php

declare(strict_types=1);

use App\E2E\Support\E2EImage;
use App\E2E\Support\ProviderPool;

pest()->group('e2e-provision');

it('can reach an E2E provider with the required reusable images', function (): void {
    $selection = ProviderPool::fromEnvironment()->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    expect($selection->message)->toContain($selection->provider()->name());
});
