<?php

declare(strict_types=1);

use App\Services\Activity\ActivityPayloadFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('derives effect and channel from stored type without rewriting workload keys', function (): void {
    $activity = new Activity;
    $activity->log_name = 'api';
    $activity->event = 'app.shown';
    $activity->properties = [
        'type' => 'read',
        'app' => 'docs',
        'instance' => 'development',
        'command' => 'app:show',
    ];

    $formatted = ActivityPayloadFormatter::format($activity, [
        'app' => 'docs',
        'instance' => 'development',
    ]);

    expect($formatted)
        ->toMatchArray([
            'effect' => 'read',
            'channel' => 'api',
            'properties' => [
                'app' => 'docs',
                'instance' => 'development',
            ],
        ])
        ->and($formatted['properties'])
        ->not
        ->toHaveKey('project')
        ->toHaveKey('instance');
});

it('preserves the channel when reading a stored CLI activity', function (): void {
    $activity = new Activity;
    $activity->log_name = 'cli';
    $activity->event = 'app.shown';
    $activity->properties = [
        'type' => 'read',
    ];

    $formatted = ActivityPayloadFormatter::format($activity, []);

    expect($formatted)
        ->toMatchArray([
            'effect' => 'read',
            'channel' => 'cli',
        ]);
});

it('preserves legacy SSH host-key effect channel and host_key_type presentation exactly', function (): void {
    $activity = new Activity;
    $activity->log_name = 'security';
    $activity->event = 'node.host_key.changed';
    $activity->properties = [
        'type' => 'ssh-ed25519',
        'fingerprint' => 'SHA256:test',
    ];

    $formatted = ActivityPayloadFormatter::format($activity, [
        'fingerprint' => 'SHA256:test',
    ]);

    expect($formatted)->toMatchArray([
        'effect' => 'write',
        'channel' => 'api',
        'properties' => [
            'fingerprint' => 'SHA256:test',
            'host_key_type' => 'ssh-ed25519',
        ],
    ]);
});

it('does not invent host_key_type when already present on host-key activities', function (): void {
    $activity = new Activity;
    $activity->log_name = 'security';
    $activity->event = 'node.host_key.pinned';
    $activity->properties = [
        'type' => 'ssh-ed25519',
    ];

    $formatted = ActivityPayloadFormatter::format($activity, [
        'host_key_type' => 'ssh-rsa',
    ]);

    expect($formatted['properties']['host_key_type'])
        ->toBe('ssh-rsa')
        ->and($formatted['effect'])
        ->toBe('write')
        ->and($formatted['channel'])
        ->toBe('api');
});

it('does not translate project or app_instance property keys at runtime', function (): void {
    $activity = new Activity;
    $activity->log_name = 'api';
    $activity->event = 'app.shown';
    $activity->properties = [
        'type' => 'read',
        'project' => 'legacy',
        'instance' => 'development',
    ];

    $formatted = ActivityPayloadFormatter::format($activity, [
        'project' => 'legacy',
        'instance' => 'development',
    ]);

    // Runtime leaves unmigrated keys as-is; migration rewrites stored JSON.
    expect($formatted['properties'])->toBe([
        'project' => 'legacy',
        'instance' => 'development',
    ]);
});
