<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\Requests\Apps\ShowAppWorkerRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createWorkerCommandApp(array $overrides = []): App
{
    $node = Node::factory()->appDev(['tld' => 'test'])->create(['name' => 'app-1', 'tld' => 'test']);

    return App::factory()->for($node, 'node')->create(array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $overrides));
}

/**
 * @param  list<RemoteShellResult>  $results
 */
function bindWorkerCommandShell(array $results = []): void
{
    app()->instance(RemoteShell::class, new class($results) implements RemoteShell
    {
        public function __construct(public array $results) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return array_shift($this->results) ?? new RemoteShellResult(
                exitCode: 0,
                stdout: "octane:installed\nfrankenphp-worker-file:present\nfrankenphp:configured\n",
                stderr: '',
                durationMs: 1,
            );
        }
    });
}

describe('app:worker command', function (): void {
    it('shows worker disabled by default for a freshly created app', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['action' => 'show', 'app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toMatchArray([
                'app' => 'docs',
                'worker_enabled' => false,
                'worker_config' => null,
            ]);
    });

    it('enables worker mode after a passing readiness probe and stores worker_config', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell([
            new RemoteShellResult(exitCode: 0, stdout: "octane:installed\nfrankenphp-worker-file:present\nfrankenphp:configured\n", stderr: '', durationMs: 1),
        ]);

        $exitCode = Artisan::call('app:worker', ['action' => 'enable', 'app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['worker_enabled'])->toBeTrue()
            ->and($payload['success']['data']['worker_config'])->toBe([
                'workers' => 'auto',
                'max_requests' => 500,
            ]);

        $app = App::query()->where('name', 'docs')->first();
        expect($app->worker_enabled)->toBeTrue()
            ->and($app->worker_config)->toBe([
                'workers' => 'auto',
                'max_requests' => 500,
            ]);
    });

    it('refuses to enable worker mode when Octane/FrankenPHP readiness fails and leaves state unchanged', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $exitCode = Artisan::call('app:worker', ['action' => 'enable', 'app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.worker_readiness_failed')
            ->and($payload['error']['meta']['missing'])->toBe([
                'vendor/laravel/octane',
                'public/frankenphp-worker.php',
                'octane.server=frankenphp',
            ]);

        $app = App::query()->where('name', 'docs')->first();
        expect($app->worker_enabled)->toBeFalse()
            ->and($app->worker_config)->toBeNull();
    });

    it('refuses to enable worker mode for static apps without mutating state', function (): void {
        createWorkerCommandApp(['runtime_kind' => AppRuntimeKind::Static]);
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['action' => 'enable', 'app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.worker_unsupported_runtime');

        $app = App::query()->where('name', 'docs')->first();
        expect($app->worker_enabled)->toBeFalse();
    });

    it('disables worker mode and keeps the stored worker_config', function (): void {
        createWorkerCommandApp([
            'worker_enabled' => true,
            'worker_config' => [
                'workers' => 4,
                'max_requests' => 1000,
            ],
        ]);
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['action' => 'disable', 'app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['worker_enabled'])->toBeFalse()
            ->and($payload['success']['data']['worker_config'])->toBe([
                'workers' => 4,
                'max_requests' => 1000,
            ]);

        $app = App::query()->where('name', 'docs')->first();
        expect($app->worker_enabled)->toBeFalse()
            ->and($app->worker_config)->toBe([
                'workers' => 4,
                'max_requests' => 1000,
            ]);
    });

    it('reports app not found for an unknown selector', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['action' => 'show', 'app' => 'missing', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.not_found');
    });

    it('rejects an unknown action without touching app state', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['action' => 'toggle', 'app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['allowed'])->toBe(['show', 'enable', 'disable']);
    });

    it('returns the documented validation_failed JSON envelope when the action argument is missing', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($output)->not->toContain('Not enough arguments')
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('action')
            ->and($payload['error']['meta']['allowed'])->toBe(['show', 'enable', 'disable']);
    });

    it('resolves by exact app name first; a domain match on a different app does not win when a name match exists', function (): void {
        $node = Node::factory()->appDev(['tld' => 'test'])->create(['name' => 'app-1', 'tld' => 'test']);

        // App "alpha" carries the domain. If resolution short-circuits on
        // the domain match it will return "alpha" instead of "docs.test".
        App::factory()->for($node, 'node')->create([
            'name' => 'alpha',
            'domain' => 'docs.test',
            'path' => '/home/orbit/apps/alpha',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs.test',
            'domain' => 'other.test',
            'path' => '/home/orbit/apps/docs.test',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['action' => 'show', 'app' => 'docs.test', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app'])->toBe('docs.test');
    });

    it('renders the human status line for app:worker show when worker mode is disabled', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell();

        $this->artisan('app:worker show docs')
            ->expectsOutputToContain("App 'docs' worker mode is disabled.")
            ->assertExitCode(0);
    });

    it('renders the human status line and worker_config summary on app:worker enable success', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell();

        $this->artisan('app:worker enable docs')
            ->expectsOutputToContain("App 'docs' worker mode enabled.")
            ->expectsOutputToContain('workers: auto')
            ->expectsOutputToContain('max_requests: 500')
            ->assertExitCode(0);
    });

    it('renders the human status line on app:worker disable success', function (): void {
        createWorkerCommandApp([
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
        ]);
        bindWorkerCommandShell();

        $this->artisan('app:worker disable docs')
            ->expectsOutputToContain("App 'docs' worker mode disabled.")
            ->assertExitCode(0);
    });

    it('renders the human readiness-failure error line and exits 1 without mutating state', function (): void {
        createWorkerCommandApp();
        bindWorkerCommandShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $this->artisan('app:worker enable docs')
            ->expectsOutputToContain("App 'docs' is not ready for worker mode.")
            ->assertExitCode(1);

        expect(App::query()->where('name', 'docs')->value('worker_enabled'))->toBeFalse();
    });

    it('returns gateway_unavailable when a control-mode caller cannot reach the gateway worker endpoint', function (): void {
        config(['orbit.is_gateway' => false]);
        Node::factory()->operator()->create(['name' => 'control-1']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        // No App row needs to exist; the command forwards before any local DB read.
        MockClient::global([
            ShowAppWorkerRequest::class => MockResponse::make([], 500),
        ]);

        $exitCode = Artisan::call('app:worker', ['action' => 'show', 'app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('returns app.worker_missing_path when enabling worker mode on an app with an empty source path', function (): void {
        createWorkerCommandApp(['path' => '']);
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['action' => 'enable', 'app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.worker_missing_path');

        $reloaded = App::query()->where('name', 'docs')->first();
        expect($reloaded->worker_enabled)->toBeFalse();
    });

    it('falls back to domain match when no name matches the selector', function (): void {
        $node = Node::factory()->appDev(['tld' => 'test'])->create(['name' => 'app-1', 'tld' => 'test']);

        App::factory()->for($node, 'node')->create([
            'name' => 'alpha',
            'domain' => 'docs.example.com',
            'path' => '/home/orbit/apps/alpha',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindWorkerCommandShell();

        $exitCode = Artisan::call('app:worker', ['action' => 'show', 'app' => 'docs.example.com', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app'])->toBe('alpha');
    });
});
