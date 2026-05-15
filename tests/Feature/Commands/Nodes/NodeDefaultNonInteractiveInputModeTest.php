<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
});

describe('node:default non-interactive input mode', function (): void {
    it('returns the documented JSON error for mutually exclusive name and clear inputs', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--clear' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Provide only one node target.')
            ->and($payload['error']['meta'])->toBe(['fields' => ['name', 'clear']]);
    });
});
