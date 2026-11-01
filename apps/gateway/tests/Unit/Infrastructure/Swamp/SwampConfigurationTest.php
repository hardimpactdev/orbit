<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the production TLS port and approved public SSH identity by default', function (): void {
    expect(config('swamp.port'))->toBe(443)
        ->and(config('swamp.ssh_public_key'))->toBe('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND');
});

it('reads the configured sandbox token from a private file instead of exposing it in environment settings', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'orbit-swamp-config-');
    file_put_contents($path, 'fixture-run-token.secret');
    chmod($path, 0600);
    Env::getRepository()->set('ORBIT_SWAMP_RUN_TOKEN_FILE', $path);
    Env::getRepository()->set('ORBIT_SWAMP_PORT', '19470');
    try {
        $settings = require base_path('config/swamp.php');
        expect($settings['run_token'])->toBe('fixture-run-token.secret')
            ->and($settings['port'])->toBe(19470);
    } finally {
        Env::getRepository()->clear('ORBIT_SWAMP_RUN_TOKEN_FILE');
        Env::getRepository()->clear('ORBIT_SWAMP_PORT');
        unlink($path);
    }
});

it('refuses an unsafe token file instead of using the inline token', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'orbit-swamp-config-');
    file_put_contents($path, 'fixture-run-token.secret');
    chmod($path, 0644);
    Env::getRepository()->set('ORBIT_SWAMP_RUN_TOKEN_FILE', $path);
    try {
        expect(fn () => require base_path('config/swamp.php'))
            ->toThrow(RuntimeException::class, 'Swamp token file must be an owned mode-0600 regular file.');
    } finally {
        Env::getRepository()->clear('ORBIT_SWAMP_RUN_TOKEN_FILE');
        unlink($path);
    }
});
