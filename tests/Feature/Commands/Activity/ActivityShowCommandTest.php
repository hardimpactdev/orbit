<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Activity\ShowActivityRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

function createActivityShowLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.9',
        'wireguard_address' => '10.6.0.9',
        'is_local' => true,
    ]);
}

function createCommandShowActivityEntry(
    string $type,
    string $effect,
    Node $actor,
    ?string $correlation = null,
): Activity {
    $activity = activity('api')
        ->causedBy($actor)
        ->event($type)
        ->withProperties([
            'type' => $effect,
            'command' => str_replace('.', ':', $type),
            'node' => $actor->name,
        ])
        ->log("Recorded {$type}");

    if ($correlation !== null) {
        $activity->forceFill(['batch_uuid' => $correlation])->save();
    }

    return $activity;
}

describe('activity:show command', function (): void {
    it('shows one activity with details and related entries for gateway callers', function (): void {
        $caller = createActivityShowLocalNode('gateway');
        $correlation = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $firstRelated = createCommandShowActivityEntry('node.create_requested', 'read', $caller, $correlation);
        $selected = createCommandShowActivityEntry('node.created', 'write', $caller, $correlation);
        $secondRelated = createCommandShowActivityEntry('node.updated', 'write', $caller, $correlation);

        $firstRelated->forceFill(['created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)])->save();
        $selected->forceFill(['created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()])->save();
        $secondRelated->forceFill(['created_at' => now(), 'updated_at' => now()])->save();

        $exitCode = Artisan::call('activity:show', [
            'id' => (string) $selected->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['activity']['id'])->toBe($selected->id)
            ->and($payload['success']['data']['activity']['type'])->toBe('node.created')
            ->and($payload['success']['data']['activity']['effect'])->toBe('write')
            ->and($payload['success']['data']['activity']['details']['node'])->toBe('local-gateway')
            ->and($payload['success']['meta']['related_count'])->toBe(2);

        expect(array_column($payload['success']['data']['related'], 'id'))->toBe([$firstRelated->id, $secondRelated->id]);
    });

    it('renders a human detail view without a progress tree', function (): void {
        $caller = createActivityShowLocalNode('gateway');
        $selected = createCommandShowActivityEntry('activity.shown', 'read', $caller);

        $this->artisan("activity:show {$selected->id}")
            ->expectsOutputToContain("Activity {$selected->id}")
            ->expectsOutputToContain('Type: activity.shown')
            ->expectsOutputToContain('Effect: read')
            ->expectsOutputToContain('Related: none')
            ->doesntExpectOutputToContain('"success"')
            ->doesntExpectOutputToContain('┌')
            ->assertSuccessful();
    });

    it('validates required positive integer ids before gateway reads', function (array $arguments, string $reason, string $message): void {
        $exitCode = Artisan::call('activity:show', array_merge($arguments, [
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe($message)
            ->and($payload['error']['meta'])->toBe([
                'field' => 'id',
                'reason' => $reason,
            ]);
    })->with([
        'missing id' => [[], 'missing', 'Activity id is required.'],
        'invalid id' => [['id' => 'nope'], 'invalid', 'Activity id must be a positive integer.'],
        'zero id' => [['id' => '0'], 'invalid', 'Activity id must be a positive integer.'],
    ]);

    it('returns activity_not_found when a gateway-local id is missing', function (): void {
        createActivityShowLocalNode('gateway');

        $exitCode = Artisan::call('activity:show', [
            'id' => '999',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('activity_not_found')
            ->and($payload['error']['message'])->toBe('Activity 999 was not found or is not visible.')
            ->and($payload['error']['meta']['id'])->toBe(999);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createActivityShowLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowActivityRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'activity' => [
                            'id' => 42,
                            'occurred_at' => '2026-05-02T08:30:00+00:00',
                            'correlation_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                            'type' => 'node.created',
                            'effect' => 'write',
                            'subject' => ['type' => 'node', 'name' => 'app-1'],
                            'actor' => ['node' => 'control-1', 'role' => 'control'],
                            'command' => 'node:new',
                            'summary' => 'Recorded node.created',
                            'details' => ['node' => 'app-1'],
                        ],
                        'related' => [],
                    ],
                    'meta' => [
                        'related_count' => 0,
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('activity:show', [
            'id' => '42',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['activity']['id'])->toBe(42)
            ->and($payload['success']['meta']['related_count'])->toBe(0);
    });

    it('preserves gateway authorization failures for forwarded callers', function (): void {
        createActivityShowLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowActivityRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to read gateway activity history.',
                    'meta' => [],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('activity:show', [
            'id' => '42',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['message'])->toBe('This node is not authorized to read gateway activity history.');
    });
});
