<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Activity\ListActivityRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

function createActivityListLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

function createCommandActivityEntry(
    string $type,
    string $effect,
    Node $actor,
    ?Model $subject = null,
): Activity {
    $logger = activity('api')
        ->causedBy($actor)
        ->event($type)
        ->withProperties([
            'type' => $effect,
            'command' => str_replace('.', ':', $type),
        ]);

    if ($subject !== null) {
        $logger = $logger->performedOn($subject);
    }

    return $logger->log("Recorded {$type}");
}

describe('activity:list command', function (): void {
    it('lists destructive activity locally for gateway callers', function (): void {
        $caller = createActivityListLocalNode('gateway');
        $appNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);

        createCommandActivityEntry('node.listed', 'read', $caller);
        $removed = createCommandActivityEntry('app.removed', 'destructive', $caller, $app);

        $exitCode = Artisan::call('activity:list', [
            '--json' => true,
            '--effect' => 'destructive',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['activities'])->toHaveCount(1)
            ->and($payload['success']['data']['activities'][0]['id'])->toBe($removed->id)
            ->and($payload['success']['data']['activities'][0]['effect'])->toBe('destructive')
            ->and($payload['success']['data']['activities'][0]['subject'])->toBe(['type' => 'app', 'name' => 'docs'])
            ->and($payload['success']['meta']['filters']['effect'])->toBe('destructive');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createActivityListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListActivityRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'activities' => [
                            [
                                'id' => 42,
                                'occurred_at' => '2026-05-02T08:30:00+00:00',
                                'correlation_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                                'type' => 'node.removed',
                                'effect' => 'destructive',
                                'subject' => ['type' => 'node', 'name' => 'app-1'],
                                'actor' => ['node' => 'control-1'],
                                'command' => 'node:remove',
                                'summary' => 'Removed node app-1.',
                            ],
                        ],
                    ],
                    'meta' => [
                        'filters' => [
                            'app' => null,
                            'node' => null,
                            'effect' => 'destructive',
                            'correlation' => null,
                        ],
                        'limit' => 25,
                        'count' => 1,
                        'has_more' => false,
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('activity:list', [
            '--json' => true,
            '--effect' => 'destructive',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['activities'][0]['id'])->toBe(42)
            ->and($payload['success']['meta']['filters']['effect'])->toBe('destructive');
    });

    it('rejects invalid filters before gateway reads', function (array $arguments, string $field, string $reason): void {
        $exitCode = Artisan::call('activity:list', array_merge(['--json' => true], $arguments));
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Invalid activity filter.')
            ->and($payload['error']['meta']['field'])->toBe($field)
            ->and($payload['error']['meta']['reason'])->toBe($reason);
    })->with([
        'effect' => [['--effect' => 'nope'], 'effect', 'unsupported_value'],
        'limit' => [['--limit' => '201'], 'limit', 'out_of_range'],
        'correlation' => [['--correlation' => 'not-a-uuid'], 'correlation', 'invalid'],
    ]);

    it('renders empty human results without a json envelope', function (): void {
        createActivityListLocalNode('gateway');

        $this->artisan('activity:list --effect=destructive')
            ->expectsOutput('No activity found.')
            ->doesntExpectOutputToContain('"success"')
            ->assertSuccessful();
    });
});
