<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('requires the canonical name input and treats missing role as a client-identity request', function (): void {
    config(['orbit.is_gateway' => false]);

    $missingNameExitCode = Artisan::call('node:new', ['--json' => true]);
    $missingNamePayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    $missingRoleExitCode = Artisan::call('node:new', [
        'name' => 'control-2',
        '--json' => true,
    ]);
    $missingRolePayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($missingNameExitCode)->toBe(1)
        ->and($missingNamePayload['error']['message'])->toBe('Node name is required.')
        ->and($missingRoleExitCode)->toBe(1)
        ->and($missingRolePayload['error']['message'])->toBe('Gateway connection is required before creating client identities.');
});
