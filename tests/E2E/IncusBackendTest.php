<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\ProviderPool;

it('can reach an E2E provider with the required reusable images', function (): void {
    $selection = ProviderPool::fromEnvironment()->select(E2EImage::Blank, E2EImage::Control, E2EImage::Gateway);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    expect($selection->message)->toContain($selection->provider()->name());
});
