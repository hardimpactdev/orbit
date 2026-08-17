<?php

declare(strict_types=1);

use Orbit\Sdk\Laravel\Tests\TestCase;

uses(TestCase::class);

it('does not ship the retired direct POST /api/update/all client stack', function (): void {
    expect(class_exists('Orbit\\Sdk\\Laravel\\Requests\\Operations\\UpdateAllRequest'))
        ->toBeFalse()
        ->and(class_exists('Orbit\\Sdk\\Laravel\\Requests\\Operations\\UpdateAllStreamRequest'))
        ->toBeFalse()
        ->and(class_exists('Orbit\\Sdk\\Laravel\\Responses\\Operations\\UpdateAllResponse'))
        ->toBeFalse()
        ->and(class_exists('Orbit\\Sdk\\Laravel\\UpdateAllGatewayStreamClient'))
        ->toBeFalse();
});
