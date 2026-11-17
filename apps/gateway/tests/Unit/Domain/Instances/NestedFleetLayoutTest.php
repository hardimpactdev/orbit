<?php

declare(strict_types=1);

use App\Domain\Instances\NestedFleetLayout;

it('gives every nested container an address inside the subnet of the nested network and a MAC of its own', function (): void {
    $layout = NestedFleetLayout::Layout;

    expect(array_unique(array_column($layout, 'address')))->toHaveCount(3)
        ->and(array_unique(array_column($layout, 'hwaddr')))->toHaveCount(3)
        ->and(array_column($layout, 'address'))->each->toStartWith('10.232.2.')
        ->and(array_column($layout, 'wireguard'))->each->toStartWith('10.45.0.');
});
