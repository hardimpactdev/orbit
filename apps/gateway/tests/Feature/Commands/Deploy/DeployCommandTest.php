<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Deploy\AddDeployStepRequest;
use App\Http\Gateway\Requests\Deploy\ListDeployHistoryRequest;
use App\Http\Gateway\Requests\Deploy\RunDeployStreamRequest;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeploymentRunStep;
use App\Models\DeployStep;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

final class FakeDeployRemoteShell implements RemoteShell
{
    public array $runs = [];

    public array $results = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = compact('node', 'script', 'options');

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: "deployed\n",
            stderr: '',
            durationMs: 25,
        );
    }
}

function deployCommandCreateApp(string $environment = 'production'): App
{
    Node::factory()->create([
        'name' => 'gateway-1',
    ]);

    $node = Node::factory()->appProd()->create([
        'name' => 'app-prod-1',
    ]);

    return App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'environment' => $environment,
        'path' => '/srv/docs',
    ]);
}

it('manages deployment policy for production apps', function (): void {
    deployCommandCreateApp();

    $addExit = Artisan::call('deploy:step-add', [
        'app' => 'docs',
        'deploy_command' => 'php artisan migrate --force',
        '--title' => 'Run migrations',
        '--order' => '1',
        '--timeout' => '300',
        '--retention' => '3',
        '--json' => true,
    ]);
    $addPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($addExit)->toBe(0)
        ->and($addPayload['success']['data']['step'])->toMatchArray([
            'app' => 'docs',
            'title' => 'Run migrations',
            'command' => 'php artisan migrate --force',
            'order' => 1,
            'timeout_seconds' => 300,
            'retention' => 3,
        ])
        ->and($addPayload['success']['meta'])->toBe(['action' => 'created']);

    Artisan::call('deploy:step-add', [
        'app' => 'docs',
        'deploy_command' => 'php artisan optimize',
        '--title' => 'Optimize',
        '--order' => '1',
        '--json' => true,
    ]);

    $listExit = Artisan::call('deploy:step-list', [
        'app' => 'docs',
        '--json' => true,
    ]);
    $listPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($listExit)->toBe(0)
        ->and(array_column($listPayload['success']['data']['steps'], 'title'))->toBe(['Optimize', 'Run migrations'])
        ->and(array_column($listPayload['success']['data']['steps'], 'order'))->toBe([1, 2])
        ->and($listPayload['success']['meta']['count'])->toBe(2);

    $removeExit = Artisan::call('deploy:step-remove', [
        'app' => 'docs',
        'step' => 'Optimize',
        '--force' => true,
        '--json' => true,
    ]);
    $removePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($removeExit)->toBe(0)
        ->and($removePayload['success']['data']['step']['title'])->toBe('Optimize')
        ->and($removePayload['success']['meta'])->toBe([
            'action' => 'removed',
            'history_preserved' => true,
        ])
        ->and(DeployStep::query()->sole()->sort_order)->toBe(1);
});

it('runs deployment steps and stores history and logs', function (): void {
    $app = deployCommandCreateApp();
    $shell = new FakeDeployRemoteShell;
    app()->instance(RemoteShell::class, $shell);

    DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => 'Deploy marker',
        'command' => <<<'SH'
printf '{{ release }}'
printf '{{ release_path }}'
printf '{{ app_user }}'
SH,
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);

    $runExit = Artisan::call('deploy:run', [
        'app' => 'docs',
        '--json' => true,
    ]);
    $runPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($runExit)->toBe(0)
        ->and($runPayload['success']['data']['run']['status'])->toBe('completed')
        ->and($runPayload['success']['data']['output']['stdout'])->toContain("deployed\n")
        ->and($runPayload['success']['data']['output']['stderr'])->toBe('')
        ->and($shell->runs[0]['script'])->not->toContain('{{')
        ->and($shell->runs[0]['options']['cwd'])->toBe('/srv/docs')
        ->and($shell->runs[0]['options']['timeout'])->toBe(120)
        ->and($shell->runs[0]['options']['strict'])->toBeTrue()
        ->and(App::query()->findOrFail($app->id)->latest_deployment_status)->toBe('completed');

    $run = DeploymentRun::query()->sole();
    $step = DeploymentRunStep::query()->sole();
    $release = $run->context['release'];

    $historyExit = Artisan::call('deploy:history', [
        'app' => 'docs',
        '--json' => true,
    ]);
    $historyPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($historyExit)->toBe(0)
        ->and($historyPayload['success']['data']['runs'][0]['id'])->toBe($run->id)
        ->and($historyPayload['success']['data']['runs'][0]['context']['release'])->toBe($release)
        ->and($historyPayload['success']['meta']['pagination']['limit_capped'])->toBeFalse();

    expect($run->context)
        ->toMatchArray([
            'app_name' => 'docs',
            'app_path' => '/srv/docs',
            'app_user' => 'orbit',
            'release_path' => "/srv/docs/releases/{$release}",
        ])
        ->and($release)->toMatch('/^\d{8}_\d{6}_\d+$/')
        ->and($shell->runs[0]['script'])->toContain("printf '{$release}'")
        ->and($shell->runs[0]['script'])->toContain("printf '/srv/docs/releases/{$release}'")
        ->and($shell->runs[0]['options']['metadata']['ORBIT_DEPLOY_RELEASE'])->toBe($release)
        ->and($step->command)->toBe($shell->runs[0]['script']);

    $logExit = Artisan::call('deploy:log', [
        'app' => 'docs',
        'run' => (string) $run->id,
        '--step' => (string) $step->id,
        '--lines' => '20',
        '--json' => true,
    ]);
    $logPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($logExit)->toBe(0)
        ->and($logPayload['success']['data']['steps'][0]['output'])->toBe([
            'stdout' => "deployed\n",
            'stderr' => '',
        ])
        ->and($logPayload['success']['meta']['lines'])->toBe(20);
});

it('renders a progress tree while running deployment steps', function (): void {
    $app = deployCommandCreateApp();
    $shell = new FakeDeployRemoteShell;
    app()->instance(RemoteShell::class, $shell);

    DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => 'Install dependencies',
        'command' => 'composer install --no-interaction',
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);

    DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => 'Run migrations',
        'command' => 'php artisan migrate --force',
        'sort_order' => 2,
        'timeout_seconds' => 120,
    ]);

    $this->artisan('deploy:run', [
        'app' => 'docs',
    ])
        ->expectsOutputToContain('┌  Running Deployment')
        ->expectsOutputToContain('○  Create deployment run')
        ->expectsOutputToContain('○  Install dependencies')
        ->expectsOutputToContain('○  Run migrations')
        ->expectsOutputToContain('●  Created deployment run')
        ->expectsOutputToContain('●  Install dependencies')
        ->expectsOutputToContain('●  Run migrations')
        ->expectsOutputToContain('└  Deployment completed')
        ->expectsOutputToContain('Deployment completed for docs (run #')
        ->assertSuccessful();
});

it('streams deploy run progress through the gateway for control callers', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $stream = [
        [
            'event' => 'tree',
            'data' => [
                'title' => 'Running Deployment',
                'steps' => [
                    [
                        'key' => 'resolve-app',
                        'label' => 'Resolve production app',
                        'doneLabel' => 'Resolved production app',
                    ],
                    [
                        'key' => 'create-run',
                        'label' => 'Create deployment run',
                        'doneLabel' => 'Created deployment run',
                    ],
                    [
                        'key' => 'deploy-step-123',
                        'label' => 'Install dependencies',
                        'doneLabel' => 'Install dependencies',
                    ],
                    [
                        'key' => 'record-result',
                        'label' => 'Record deployment result',
                        'doneLabel' => 'Recorded deployment result',
                    ],
                ],
            ],
        ],
        ['event' => 'step', 'data' => ['key' => 'resolve-app', 'status' => 'start']],
        ['event' => 'step', 'data' => ['key' => 'resolve-app', 'status' => 'done', 'message' => 'docs']],
        ['event' => 'step', 'data' => ['key' => 'create-run', 'status' => 'start']],
        ['event' => 'step', 'data' => ['key' => 'create-run', 'status' => 'done', 'message' => '#123']],
        ['event' => 'step', 'data' => ['key' => 'deploy-step-123', 'status' => 'start']],
        ['event' => 'step', 'data' => ['key' => 'deploy-step-123', 'status' => 'done', 'message' => '25ms']],
        ['event' => 'step', 'data' => ['key' => 'record-result', 'status' => 'start']],
        ['event' => 'step', 'data' => ['key' => 'record-result', 'status' => 'done', 'message' => 'completed']],
        [
            'event' => 'complete',
            'data' => [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Deployment completed',
                    'run' => [
                        'id' => 123,
                        'app' => 'docs',
                        'status' => 'completed',
                        'exit_code' => 0,
                        'steps' => [],
                    ],
                ],
            ],
        ],
    ];
    $streamBody = collect($stream)
        ->map(fn (array $frame): string => "event: {$frame['event']}\n".'data: '.json_encode($frame['data'], JSON_THROW_ON_ERROR)."\n\n")
        ->implode('');
    $mockClient = MockClient::global([
        RunDeployStreamRequest::class => MockResponse::make($streamBody, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $this->artisan('deploy:run', [
        'app' => 'docs',
    ])
        ->expectsOutputToContain('┌  Running Deployment')
        ->expectsOutputToContain('○  Resolve production app')
        ->expectsOutputToContain('○  Install dependencies')
        ->expectsOutputToContain('●  Resolved production app')
        ->expectsOutputToContain('●  Install dependencies')
        ->expectsOutputToContain('└  Deployment completed')
        ->expectsOutputToContain('Deployment completed for docs (run #123).')
        ->assertSuccessful();

    $mockClient->assertSent(RunDeployStreamRequest::class);
});

it('uses one deploy run API route for json and streamed responses', function (): void {
    $routes = collect(Route::getRoutes())
        ->filter(fn ($route): bool => in_array('POST', $route->methods(), true))
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => in_array($uri, ['api/deploy/run', 'api/deploy/run/stream'], true))
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe(['api/deploy/run']);
});

it('renders deployment step commands inside the table', function (): void {
    $app = deployCommandCreateApp();

    DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => 'Install PHP dependencies',
        'command' => 'sudo -n -H -u "{{ app_user }}" bash -lc \'cd "{{ release_path }}" && composer install --no-dev --optimize-autoloader --no-interaction\'',
        'sort_order' => 1,
        'timeout_seconds' => 300,
    ]);

    DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => 'Reload PHP-FPM',
        'command' => 'sudo -n systemctl reload php8.5-fpm',
        'sort_order' => 2,
        'timeout_seconds' => 120,
    ]);

    $exit = Artisan::call('deploy:step-list', [
        'app' => 'docs',
    ]);
    $output = Artisan::output();
    $firstStepOffset = strpos($output, 'Install PHP dependencies');
    $secondStepOffset = strpos($output, 'Reload PHP-FPM');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Install PHP dependencies')
        ->and($output)->toContain('Reload PHP-FPM')
        ->and($output)->toContain('COMMAND')
        ->and($output)->toContain('sudo -n -H -u "{{ app_user }}" bash -lc \'cd "{{ release_path }}" &&')
        ->and($output)->toContain('composer install --no-dev --optimize-autoloader --no-interaction\'')
        ->and(substr($output, $firstStepOffset, $secondStepOffset - $firstStepOffset))->toContain('├')
        ->and($output)->not->toContain('Commands:');
});

it('fails before side effects for non-production apps', function (): void {
    deployCommandCreateApp('development');

    $exit = Artisan::call('deploy:step-add', [
        'app' => 'docs',
        'deploy_command' => 'php artisan migrate --force',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'deploy.production_app_required',
            'meta' => [
                'app' => 'docs',
                'environment' => 'development',
            ],
        ])
        ->and(DeployStep::query()->count())->toBe(0);
});

it('forwards control callers through typed gateway requests', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $mockClient = MockClient::global([
        AddDeployStepRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'step' => [
                        'id' => 12,
                        'app' => 'docs',
                        'title' => 'Pull latest',
                        'command' => 'git pull origin main',
                        'order' => 1,
                        'timeout_seconds' => 600,
                        'retention' => null,
                    ],
                ],
                'meta' => ['action' => 'created'],
            ],
        ], 200),
        ListDeployHistoryRequest::class => MockResponse::make([
            'success' => [
                'data' => ['runs' => []],
                'meta' => [
                    'pagination' => [
                        'total' => 0,
                        'limit' => 50,
                        'limit_capped' => false,
                    ],
                ],
            ],
        ], 200),
    ]);

    $addExit = Artisan::call('deploy:step-add', [
        'app' => 'docs',
        'deploy_command' => 'git pull origin main',
        '--title' => 'Pull latest',
        '--json' => true,
    ]);
    $addPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $historyExit = Artisan::call('deploy:history', [
        'app' => 'docs',
        '--json' => true,
    ]);
    $historyPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($addExit)->toBe(0)
        ->and($addPayload['success']['data']['step']['title'])->toBe('Pull latest')
        ->and($historyExit)->toBe(0)
        ->and($historyPayload['success']['data']['runs'])->toBe([]);

    $mockClient->assertSent(AddDeployStepRequest::class);
    $mockClient->assertSent(ListDeployHistoryRequest::class);
});
