<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Apps\CreateAppRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('creates source on the target app node before writing gateway app intent', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'ssh_user' => 'orbit',
        'status' => 'active',
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs)->toHaveCount(2)
        ->and($remoteShell->runs[0]['node'])->toBe($targetNode->id)
        ->and($remoteShell->runs[0]['script'])->toContain("mkdir -p '/home/orbit/apps/docs'")
        ->and(App::query()->where('name', 'docs')->exists())->toBeTrue()
        ->and($payload['success']['data']['result']['action'])->toBe('created')
        ->and($payload['success']['data']['app'])->toMatchArray([
            'name' => 'docs',
            'node' => 'app-1',
            'environment' => 'development',
            'url' => 'https://docs.test',
            'path' => '/home/orbit/apps/docs',
            'root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'adopted' => false,
        ])
        ->and($payload['success']['meta']['warnings'])->toBe([]);
});

it('canonicalizes github shorthand repositories before source creation and registry write', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'ssh_user' => 'deploy',
        'status' => 'active',
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'api',
        '--node' => 'app-1',
        '--repo' => 'acme/api',
        '--root' => 'web',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($remoteShell->runs[0]['script'])->toContain("git clone 'git@github.com:acme/api.git' '/home/deploy/apps/api'")
        ->and(App::query()->where('name', 'api')->value('repository'))->toBe('git@github.com:acme/api.git')
        ->and($payload['success']['data']['app']['repository'])->toBe('git@github.com:acme/api.git')
        ->and($payload['success']['data']['app']['root'])->toBe('web');
});

it('does not write gateway app intent when source creation fails', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    $remoteShell = new RecordingRemoteShell(new RemoteShellResult(
        exitCode: 128,
        stdout: '',
        stderr: "permission denied\n",
        durationMs: 5,
    ));
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--repo' => 'git@github.com:acme/docs.git',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(App::query()->where('name', 'docs')->exists())->toBeFalse()
        ->and($payload['error']['code'])->toBe('app.source_creation_failed')
        ->and($payload['error']['meta'])->toMatchArray([
            'reason' => 'permission denied',
            'transport' => 'ssh',
        ]);
});

it('keeps gateway app intent and reports a warning when runtime enactment needs later convergence', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new SequencedRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 127, stdout: '', stderr: "php-fpm missing\n", durationMs: 1),
    ]));

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->exists())->toBeTrue()
        ->and($payload['success']['meta']['warnings'])->toHaveCount(1)
        ->and($payload['success']['meta']['warnings'][0])->toMatchArray([
            'code' => 'app.php_version_unavailable',
            'family' => 'app',
            'next_command' => 'doctor --family=app --fix',
        ]);
});

it('fails before remote work when the app name is already registered', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $existingNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $existingNode->id,
    ]);

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($remoteShell->runs)->toBe([])
        ->and($payload['error']['code'])->toBe('app.collision')
        ->and($payload['error']['meta'])->toMatchArray([
            'name' => 'docs',
            'node' => 'app-1',
        ]);
});

it('forwards configured control callers through the typed gateway request', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $remoteShell = new RecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    MockClient::global([
        CreateAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'environment' => 'development',
                        'url' => 'https://docs.test',
                        'path' => '/home/orbit/apps/docs',
                        'root' => 'public',
                        'repository' => null,
                        'php_version' => '8.5',
                        'adopted' => false,
                    ],
                ],
                'meta' => ['warnings' => []],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['app']['name'])->toBe('docs')
        ->and(App::query()->count())->toBe(0)
        ->and($remoteShell->runs)->toBe([]);
});

final class RecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    public function __construct(
        private readonly ?RemoteShellResult $result = null,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->id,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}

final class SequencedRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
