<?php

declare(strict_types=1);

use App\Services\Vpn\ArrayVpnBackend;
use App\Services\Vpn\VpnBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('rotates the vpn web ui password without printing the secret', function (): void {
    vpnLocalNode('gateway');
    $backend = new ArrayVpnBackend;
    app()->instance(VpnBackend::class, $backend);

    $exitCode = Artisan::call('vpn-web-ui:change-password', [
        'password' => 'new-secret-password',
        '--force' => true,
        '--json' => true,
    ]);
    $output = Artisan::output();
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['vpn'])->toBe([
            'password_changed' => true,
            'sessions_invalidated' => true,
        ])
        ->and($output)->not->toContain('new-secret-password')
        ->and($backend->changedPassword)->toBe('new-secret-password');
});

it('requires password and force in json mode', function (): void {
    vpnLocalNode('gateway');
    app()->instance(VpnBackend::class, new ArrayVpnBackend);

    $missingPassword = Artisan::call('vpn-web-ui:change-password', ['--force' => true, '--json' => true]);
    $missingPasswordPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    $missingForce = Artisan::call('vpn-web-ui:change-password', [
        'password' => 'new-secret-password',
        '--json' => true,
    ]);
    $missingForcePayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($missingPassword)->toBe(1)
        ->and($missingPasswordPayload['error']['meta']['field'])->toBe('password')
        ->and($missingForce)->toBe(1)
        ->and($missingForcePayload['error']['meta'])->toBe([
            'field' => 'force',
            'reason' => 'destructive_consent_required',
        ]);
});

it('rotates the wg-easy backend password through argon2 and sqlite without argv secrets', function (): void {
    vpnLocalNode('gateway');

    config()->set('services.wg_easy.username', 'orbit');
    config()->set('services.wg_easy.password', 'current-secret-password');
    config()->set('services.wg_easy.database_path', '/home/orbit/.wg-easy/wg-easy.db');

    Http::fake([
        'http://127.0.0.1:51821/api/session' => Http::response(['status' => 'success'], 200, [
            'Set-Cookie' => 'wg-easy=session-token; Path=/; HttpOnly',
        ]),
        'http://127.0.0.1:51821/api/client' => Http::response([], 200),
    ]);

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if (str_contains($command, 'docker exec -i -w /app/server wg-easy node')) {
            return Process::result('$argon2id$v=19$m=65536,t=3,p=4$hash$hash');
        }

        return Process::result();
    });

    $newPassword = 'new-secret-password';
    $exitCode = Artisan::call('vpn-web-ui:change-password', [
        'password' => $newPassword,
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['vpn']['password_changed'])->toBeTrue();

    Process::assertRan(function ($process) use ($newPassword): bool {
        $command = (string) $process->command;

        return str_contains($command, 'docker exec -i -w /app/server wg-easy node')
            && ! str_contains($command, $newPassword)
            && $process->input === $newPassword;
    });

    Process::assertRan(function ($process): bool {
        $command = (string) $process->command;

        return str_contains($command, 'sqlite3')
            && str_contains($command, 'wg-easy.db')
            && ! str_contains($command, 'UPDATE users_table')
            && is_string($process->input)
            && str_contains($process->input, 'UPDATE users_table SET password');
    });
});
