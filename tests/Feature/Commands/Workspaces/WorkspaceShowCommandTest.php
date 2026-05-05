<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Workspaces\ShowWorkspaceRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createWorkspaceShowLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('workspace:show base contract', function (): void {
    it('shows workspace registry details for gateway callers', function (): void {
        createWorkspaceShowLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => null]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $exitCode = Artisan::call('workspace:show', [
            'name' => 'feature-docs',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['registry_only'])->toBeTrue()
            ->and($payload['success']['data']['workspace']['name'])->toBe('feature-docs')
            ->and($payload['success']['data']['workspace']['app'])->toBe('docs')
            ->and($payload['success']['data']['workspace']['url'])->toBe('https://feature-docs.docs.test')
            ->and($payload['success']['data']['workspace'])->not->toHaveKey('live_checks');
    });

    it('fails when name is missing in non-interactive mode', function (): void {
        createWorkspaceShowLocalNode('gateway');

        $exitCode = Artisan::call('workspace:show', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'validation_failed',
                'message' => 'Workspace name is required.',
                'meta' => [
                    'field' => 'name',
                ],
            ]);
    });

    it('prompts for a missing workspace name in interactive mode', function (): void {
        createWorkspaceShowLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $this->artisan('workspace:show')
            ->expectsQuestion('Workspace name', 'feature-docs')
            ->expectsOutputToContain('Workspace: feature-docs')
            ->assertSuccessful();
    });

    it('fails when a workspace name is ambiguous without app disambiguation', function (): void {
        createWorkspaceShowLocalNode('gateway');
        $docs = App::factory()->create(['name' => 'docs']);
        $api = App::factory()->create(['name' => 'api']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $docs->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $api->id]);

        $exitCode = Artisan::call('workspace:show', ['name' => 'feature-docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.ambiguous_name')
            ->and($payload['error']['meta']['apps'])->toBe(['docs', 'api']);
    });

    it('prompts for parent app when the workspace name is ambiguous in interactive mode', function (): void {
        createWorkspaceShowLocalNode('gateway');
        $docs = App::factory()->create(['name' => 'docs']);
        $api = App::factory()->create(['name' => 'api']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $docs->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $api->id]);

        $this->artisan('workspace:show feature-docs')
            ->expectsChoice('Parent app', 'api', ['docs', 'api'])
            ->expectsOutputToContain('App:       api')
            ->assertSuccessful();
    });

    it('fails before input when local node role is invalid', function (): void {
        createWorkspaceShowLocalNode('invalid-role');

        $exitCode = Artisan::call('workspace:show', ['name' => 'feature-docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_context_invalid')
            ->and($payload['error']['meta']['caller_role'])->toBe('unknown');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createWorkspaceShowLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowWorkspaceRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'workspace' => [
                            'name' => 'feature-docs',
                            'app' => 'docs',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace:show', [
            'name' => 'feature-docs',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['workspace']['name'])->toBe('feature-docs');
    });

    it('forwards non-gateway cwd resolution through the typed gateway request', function (): void {
        createWorkspaceShowLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ShowWorkspaceRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'workspace' => [
                            'name' => 'feature-docs',
                            'app' => 'docs',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace:show', ['--json' => true]);

        expect($exitCode)->toBe(0);
        $mock->assertSent(fn (ShowWorkspaceRequest $request): bool => $request->resolveEndpoint() === '/api/workspaces/resolve-by-path'
            && is_string($request->path)
            && $request->path !== '');
    });

    it('preserves structured gateway authorization failures', function (): void {
        createWorkspaceShowLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowWorkspaceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => "This caller is not authorized to inspect 'feature-docs'.",
                    'meta' => ['name' => 'feature-docs', 'app' => 'docs', 'caller_role' => 'app'],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('workspace:show', ['name' => 'feature-docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
    });

    it('does not mutate workspace registry state or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createWorkspaceShowLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $workspaceCount = DB::table('workspaces')->count();
        $runCount = DB::table('workspace_runs')->count();

        $this->artisan('workspace:show feature-docs')->assertSuccessful();

        expect(DB::table('workspaces')->count())->toBe($workspaceCount)
            ->and(DB::table('workspace_runs')->count())->toBe($runCount);
        Process::assertNothingRan();
    });
});
