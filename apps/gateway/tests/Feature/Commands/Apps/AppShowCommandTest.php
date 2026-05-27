<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Apps\ShowAppRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
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

function createAppShowLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

function createShowApp(array $overrides = []): App
{
    $node = $overrides['node'] ?? Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7', 'tld' => 'test']);
    unset($overrides['node']);

    return App::factory()->create(array_merge([
        'name' => 'docs',
        'node_id' => $node->id,
        'environment' => 'development',
        'domain' => null,
        'path' => '/srv/docs',
        'document_root' => 'public',
        'repository' => null,
        'php_version' => '8.5',
        'adopted' => false,
    ], $overrides));
}

describe('app:show command contract', function (): void {
    it('shows a local gateway app by name', function (): void {
        createAppShowLocalNode('gateway');
        createShowApp();

        $exitCode = Artisan::call('app:show', ['app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app']['name'])->toBe('docs')
            ->and($payload['success']['data']['details']['node']['name'])->toBe('app-1');
    });

    it('resolves by hostname when no name matches', function (): void {
        createAppShowLocalNode('gateway');
        createShowApp(['domain' => 'docs.example.com']);

        $exitCode = Artisan::call('app:show', ['app' => 'docs.example.com', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app']['name'])->toBe('docs');
    });

    it('resolves the app from the current directory when no argument is supplied', function (): void {
        createAppShowLocalNode('gateway');
        $directory = sys_get_temp_dir().'/orbit-app-show-cwd-'.bin2hex(random_bytes(4));
        mkdir($directory);
        createShowApp(['path' => $directory]);

        $previous = getcwd();
        chdir($directory);

        try {
            $exitCode = Artisan::call('app:show', ['--json' => true]);
            $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        } finally {
            chdir((string) $previous);
            rmdir($directory);
        }

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app']['name'])->toBe('docs');
    });

    it('fails non-interactively when app cannot be resolved', function (): void {
        $exitCode = Artisan::call('app:show', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('app');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createAppShowLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowAppRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'app' => ['name' => 'docs'],
                        'details' => ['node' => ['name' => 'app-1']],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('app:show', ['app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app']['name'])->toBe('docs');
    });

    it('preserves gateway not-found errors', function (): void {
        config(['orbit.is_gateway' => false]);

        createAppShowLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowAppRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'app.not_found',
                    'message' => "App 'missing' not found or not visible.",
                    'meta' => ['app' => 'missing'],
                ],
            ], 404),
        ]);

        $exitCode = Artisan::call('app:show', ['app' => 'missing', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.not_found')
            ->and($payload['error']['meta']['app'])->toBe('missing');
    });

    it('does not mutate registry state or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createAppShowLocalNode('gateway');
        createShowApp();

        $appCount = DB::table('apps')->count();
        $nodeCount = DB::table('nodes')->count();

        $this->artisan('app:show', ['app' => 'docs'])->assertSuccessful();

        expect(DB::table('apps')->count())->toBe($appCount)
            ->and(DB::table('nodes')->count())->toBe($nodeCount);
        Process::assertNothingRan();
    });

    it('renders an app show detail tree in human mode', function (): void {
        createAppShowLocalNode('gateway');
        createShowApp();

        $exitCode = Artisan::call('app:show', ['app' => 'docs']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('┌  App: docs')
            ->and($output)->toContain('├  Domain')
            ->and($output)->not->toContain('Environment')
            ->and($output)->toContain('├  Node')
            ->and($output)->toContain('└  Routes')
            ->and($output)->not->toContain('+---');
    });
});
