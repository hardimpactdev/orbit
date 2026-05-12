<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Deploy\AddDeployStepRequest;
use App\Http\Gateway\Requests\Deploy\ListDeployHistoryRequest;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeploymentRunStep;
use App\Models\DeployStep;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

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
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-prod-1',
        'role' => 'app',
        'environment' => 'production',
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
        ->and($runPayload['success']['data']['output'])->toBe([
            'stdout' => "deployed\n",
            'stderr' => '',
        ])
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
        ->and($shell->runs[0]['options']['env']['ORBIT_DEPLOY_RELEASE'])->toBe($release)
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

it('renders deployment step commands inside the table', function (): void {
    $app = deployCommandCreateApp();

    DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => 'Install PHP dependencies',
        'command' => 'sudo -n -H -u "{{ app_user }}" bash -lc \'cd "{{ release_path }}" && composer install --no-dev --optimize-autoloader --no-interaction\'',
        'sort_order' => 1,
        'timeout_seconds' => 300,
    ]);

    $exit = Artisan::call('deploy:step-list', [
        'app' => 'docs',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Install PHP dependencies')
        ->and($output)->toContain('COMMAND')
        ->and($output)->toContain('sudo -n -H -u "{{ app_user }}" bash -lc \'cd "{{ release_path }}" &&')
        ->and($output)->toContain('composer install --no-dev --optimize-autoloader --no-interaction\'')
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
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
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
