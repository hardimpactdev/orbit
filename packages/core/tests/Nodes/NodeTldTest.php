<?php

declare(strict_types=1);

use Orbit\Core\Nodes\NodeTld;

it('accepts lowercase DNS labels', function (string $tld): void {
    expect(NodeTld::isValid($tld))->toBeTrue();
})->with([
    'single character' => 'a',
    'hyphenated label' => 'app-dev',
    'maximum length' => str_repeat('a', times: 63),
]);

it('rejects invalid or reserved labels', function (string $tld): void {
    expect(NodeTld::isValid($tld))->toBeFalse();
})->with([
    'empty' => '',
    'uppercase' => 'App',
    'leading hyphen' => '-app',
    'trailing hyphen' => 'app-',
    'nested label' => 'app.test',
    'too long' => str_repeat('a', times: 64),
    'private service namespace' => 'orbit',
]);

it('identifies the private service namespace as reserved', function (): void {
    expect(NodeTld::isReserved('orbit'))->toBeTrue()->and(NodeTld::isReserved('gateway'))->toBeFalse();
});

it('rejects non-string values', function (mixed $tld): void {
    expect(NodeTld::isValid($tld))->toBeFalse();
})->with([
    'null' => null,
    'array' => [['app']],
]);
