<?php

declare(strict_types=1);

use App\Services\Updates\PendingDesktopUpdateHandoff;
use App\Services\Updates\PendingDesktopUpdateHandoffFailure;

function pendingDesktopUpdatePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'operation_id' => '018f3f8b-5d7a-7f5e-a45b-93067a93d47e',
        'version' => '1.2.3',
        'build_id' => '2026-08-23T120000Z-abc123',
        'install_mode' => PendingDesktopUpdateHandoff::InstallModeAutomatic,
        'desktop' => [
            'sha256' => str_repeat('c', times: 64),
            'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==',
            'staged_path' => '/Users/nckrtl/.local/share/orbit/updates/desktop-cccc.tar.gz',
            'version' => '1.2.3',
            'platform' => 'darwin',
            'architecture' => 'arm64',
        ],
        'agent' => [
            'sha256' => str_repeat('e', times: 64),
            'bin_path' => '/Users/nckrtl/.local/bin/orbit-agent',
        ],
        'cli' => [
            'sha256' => str_repeat('b', times: 64),
            'bin_path' => '/Users/nckrtl/.local/bin/orbit',
        ],
    ], $overrides);
}

it('writes an owner-only pending desktop update handoff atomically', function (): void {
    $root = sys_get_temp_dir().'/orbit-desktop-handoff-'.bin2hex(random_bytes(6));
    $path = $root.'/.config/orbit/pending-desktop-update.json';

    PendingDesktopUpdateHandoff::fromArray(pendingDesktopUpdatePayload())->write($path);

    expect(is_file($path))->toBeTrue()
        ->and(decoct(fileperms($path) & 0777))->toBe('600')
        ->and(json_decode((string) file_get_contents($path), true)['install_mode'])
        ->toBe('automatic');
});

it('rejects an unsafe handoff path', function (): void {
    expect(fn () => PendingDesktopUpdateHandoff::fromArray(pendingDesktopUpdatePayload())->write(
        '/tmp/pending-desktop-update.json',
    ))->toThrow(PendingDesktopUpdateHandoffFailure::class, 'unsafe');
});

it('rejects a stale handoff identity', function (): void {
    $root = sys_get_temp_dir().'/orbit-desktop-handoff-'.bin2hex(random_bytes(6));
    $path = $root.'/.config/orbit/pending-desktop-update.json';
    PendingDesktopUpdateHandoff::fromArray(pendingDesktopUpdatePayload())->write($path);

    expect(fn () => PendingDesktopUpdateHandoff::read($path, [
        'operation_id' => 'other-operation',
        'version' => '1.2.3',
        'build_id' => '2026-08-23T120000Z-abc123',
    ]))->toThrow(PendingDesktopUpdateHandoffFailure::class, 'stale');
});

it('rejects a partial desktop identity', function (): void {
    $payload = pendingDesktopUpdatePayload();
    $payload['desktop'] = [
        'sha256' => str_repeat('c', times: 64),
    ];

    expect(fn () => PendingDesktopUpdateHandoff::fromArray($payload))
        ->toThrow(PendingDesktopUpdateHandoffFailure::class);
});
