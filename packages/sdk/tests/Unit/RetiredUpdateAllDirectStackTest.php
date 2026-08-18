<?php

declare(strict_types=1);

use Orbit\Sdk\Laravel\Tests\TestCase;

uses(TestCase::class);

it('does not ship the retired direct POST /api/update/all client stack', function (): void {
    $src = dirname(path: __DIR__, levels: 2).'/src';
    $requestFiles = glob($src.'/Requests/Operations/UpdateAll*');
    $responseFiles = glob($src.'/Responses/Operations/UpdateAll*');
    $clientFiles = glob($src.'/UpdateAll*');

    expect([
        ...($requestFiles === false ? [] : $requestFiles),
        ...($responseFiles === false ? [] : $responseFiles),
        ...($clientFiles === false ? [] : $clientFiles),
    ])->toBeEmpty();
});
