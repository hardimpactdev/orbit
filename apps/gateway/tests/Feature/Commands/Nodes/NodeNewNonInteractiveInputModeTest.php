<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('uses non-interactive mode for json and never prompts for missing input', function (): void {
    config(['orbit.is_gateway' => false]);

    $exitCode = Artisan::call('node:new', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['message'])->toBe('Node name is required.');
});

it('rejects invalid host values before side effects', function (): void {
    config(['orbit.is_gateway' => false]);

    $exitCode = Artisan::call('node:new', [
        'name' => 'app-1',
        '--roles' => 'app-prod',
        '--host' => 'incorrect-host',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['message'])->toBe('Host must be a valid IP address or dotted DNS name.')
        ->and($payload['error']['meta']['field'])->toBe('host');
});
