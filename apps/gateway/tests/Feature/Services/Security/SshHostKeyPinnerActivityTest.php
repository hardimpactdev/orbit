<?php

declare(strict_types=1);

use App\Services\Activity\ActivityHistory;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('records host key security events on the canonical api activity channel', function (): void {
    $publicKey = 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests';

    Process::fake([
        'ssh-keyscan*' => Process::result(output: "203.0.113.10 ssh-ed25519 {$publicKey}\n"),
    ]);
    Process::preventStrayProcesses();

    app(SshHostKeyPinner::class)->pin('203.0.113.10');

    $history = app(ActivityHistory::class)->list([
        'project' => null,
        'node' => null,
        'effect' => null,
        'correlation' => null,
        'include_internal' => true,
        'limit' => 10,
    ]);

    expect($history['activities'])->toHaveCount(1);

    $entry = $history['activities'][0];
    /** @var array<string, mixed> $properties */
    $properties = $entry['properties'];

    expect($entry['channel'])
        ->toBe('api')
        ->and($entry['type'])
        ->toBe('node.host_key.pinned_tofu')
        ->and($entry['effect'])
        ->toBe('write')
        ->and($properties['host_key_type'])
        ->toBe('ssh-ed25519')
        ->and($properties['host'])
        ->toBe('203.0.113.10')
        ->and($properties['fingerprint'])
        ->toBe(SshHostKeyPinner::fingerprintForPublicKey($publicKey));
});

it('normalizes pre-existing host key activity rows to the canonical activity dto', function (): void {
    $activity = activity('security')
        ->event('node.host_key.pinned_tofu')
        ->withProperties([
            'type' => 'ssh-ed25519',
            'host' => '203.0.113.10',
            'fingerprint' => 'SHA256:legacy',
        ])
        ->log('node.host_key.pinned_tofu');

    $history = app(ActivityHistory::class)->list([
        'project' => null,
        'node' => null,
        'effect' => 'write',
        'correlation' => null,
        'include_internal' => false,
        'limit' => 10,
    ]);

    expect($history['activities'])->toHaveCount(1);

    $entry = $history['activities'][0];
    /** @var array<string, mixed> $properties */
    $properties = $entry['properties'];

    expect($entry['id'])
        ->toBe($activity->id)
        ->and($entry['effect'])
        ->toBe('write')
        ->and($entry['channel'])
        ->toBe('api')
        ->and($properties['host_key_type'])
        ->toBe('ssh-ed25519')
        ->and($properties['host'])
        ->toBe('203.0.113.10')
        ->and($properties['fingerprint'])
        ->toBe('SHA256:legacy');

    $show = app(ActivityHistory::class)->show($activity->id);

    expect($show)
        ->not
        ->toBeNull()
        ->and($show['activity']['effect'])
        ->toBe('write')
        ->and($show['activity']['channel'])
        ->toBe('api')
        ->and($show['activity']['properties']['host_key_type'])
        ->toBe('ssh-ed25519');
});
