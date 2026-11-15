<?php

declare(strict_types=1);

use App\Actions\Instances\Dependencies\ReadSwampDependenciesAction;
use App\Domain\Instances\Dependencies\SwampDependencyReader;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Dependencies\WebSocketSwampDependencyReader;
use App\Infrastructure\Swamp\SwampCaller;
use App\Infrastructure\Swamp\SwampHandshakes;
use App\Infrastructure\Swamp\SwampReadBusy;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\HandshakeCountingServe;

use function Pest\Laravel\withServerVariables;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->freezeSecond();
    config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $this->serve = new HandshakeCountingServe;
    $serve = $this->serve;
    app()->bind(SwampDependencyReader::class, static fn (): SwampDependencyReader => new WebSocketSwampDependencyReader(
        static fn (int $limit): HandshakeCountingServe => $serve, 'swamp.test', 'read-token', '/test/ca.pem'));
});

function readEndpoint(string $token = 'read-token'): WebSocketEndpoint
{
    return new WebSocketEndpoint('swamp.test', 443, 'swamp.orbit', '/', '/test/ca.pem', $token);
}

/** A Project with a published dependency index on its default branch, when `$indexed`. */
function readProject(HandshakeCountingServe $serve, int $index, bool $indexed = true): Project
{
    $project = Project::query()->create(['name' => 'deps-'.$index, 'slug' => 'deps-'.$index, 'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/example/deps-'.$index.'.git', 'default_branch' => 'main', 'root' => '.', 'is_laravel' => false]);
    if ($indexed) {
        $serve->data['dependencies-v1-project-'.$project->id.'-'.hash('sha256', 'refs/heads/main')] = json_encode([
            'schema_version' => 1, 'publication_id' => (string) Str::uuid(), 'project_id' => $project->id, 'repository' => $project->repository_identity,
            'source_ref' => 'refs/heads/main', 'source_commit' => null, 'generated_at' => '2026-11-15T05:00:00Z',
            'last_attempt' => ['run_id' => 'run-'.$project->id, 'started_at' => '2026-11-15T05:00:00Z', 'finished_at' => null, 'status' => 'running', 'error_code' => null],
            'sources' => [], 'batch_pr' => ['status' => 'absent', 'checked_at' => '2026-11-15T05:00:00Z', 'last_success_at' => '2026-11-15T05:00:00Z', 'error_code' => null, 'value' => null],
        ], JSON_THROW_ON_ERROR);
    }

    return $project;
}

function readDependencies(Project $project): string
{
    return app(ReadSwampDependenciesAction::class)->execute($project, null, null, 1, 1)->availability;
}

/** Spends `$count` handshakes of the window as Operation starts. */
function spendWindow(HandshakeCountingServe $serve, int $count): void
{
    for ($index = 0; $index < $count; $index++) {
        app(SwampHandshakes::class)->connect($serve, readEndpoint('run-token'), 15, SwampCaller::Start);
    }
    $serve->connects = [];
}

it('reads 49 Projects\' dependencies in ten seconds on one serve handshake', function (): void {
    $projects = array_map(fn (int $index): Project => readProject($this->serve, $index), range(1, 49));

    $answers = [];
    foreach ($projects as $index => $project) {
        $answers[] = readDependencies($project);
        if ($index % 5 === 4) {
            $this->travel(1)->seconds();
        }
    }

    // Before, each read opened its own connection: 49 handshakes, most of a serve window.
    expect($this->serve->connects)->toBe(['read-token' => 1])
        ->and(array_count_values($answers))->toBe(['available' => 49]);
});

it('reads 49 Projects that have no index yet on one serve handshake', function (): void {
    $projects = array_map(fn (int $index): Project => readProject($this->serve, $index, indexed: false), range(1, 49));

    $answers = array_map(readDependencies(...), $projects);

    expect($this->serve->connects)->toBe(['read-token' => 1])
        ->and(array_count_values($answers))->toBe(['unavailable' => 49]);
});

it('reads one Project\'s dependencies as before: one handshake, one answer', function (): void {
    $project = readProject($this->serve, 1);

    expect(readDependencies($project))->toBe('available')
        ->and($this->serve->connects)->toBe(['read-token' => 1]);
});

it('answers a screen read over the view share in today\'s shape, with Retry-After and no serve connection', function (bool $cached, string $availability): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->firstOrCreate(['role' => 'gateway'], ['status' => 'active']);
    $project = readProject($this->serve, 1);
    if ($cached) {
        expect(readDependencies($project))->toBe('available');
        $this->travel(61)->seconds();
    }
    spendWindow($this->serve, SwampHandshakes::ViewShare);

    $response = withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->get('/api/v1/projects/'.$project->id.'/dependencies', ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('data.availability', $availability)->assertHeader('Retry-After');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(SwampHandshakes::WindowSeconds)
        ->and($this->serve->connects)->toBe([]);

    // The next poll inside that wait answers the same from the hold, and still names the wait.
    withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->get('/api/v1/projects/'.$project->id.'/dependencies', ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.availability', $availability)->assertHeader('Retry-After');
    expect($this->serve->connects)->toBe([]);
})->with([
    'a cached index answers stale' => [true, 'stale'],
    'no cached index answers unavailable' => [false, 'unavailable'],
]);

it('keeps priority callers connecting when a view met serve\'s 429; only views wait', function (): void {
    $serve = new HandshakeCountingServe(limit: 1);
    $ledger = app(SwampHandshakes::class);
    $ledger->connect($serve, readEndpoint(), 2, SwampCaller::Dependencies);

    expect(fn () => $ledger->connect($serve, readEndpoint(), 2, SwampCaller::Dependencies))->toThrow(WebSocketException::class);
    expect(fn () => $ledger->connect($serve, readEndpoint(), 2, SwampCaller::TaskRuns))->toThrow(SwampReadBusy::class);
    expect(fn () => $ledger->connect($serve, readEndpoint('run-token'), 15, SwampCaller::Start))->toThrow(WebSocketException::class);

    // The start still reached serve, which decided; the third view did not.
    expect($serve->connects)->toBe(['read-token' => 2, 'run-token' => 1])
        ->and($ledger->refusals())->toBe(2);
});

it('lets views connect again in the next serve window', function (): void {
    spendWindow($this->serve, SwampHandshakes::ViewShare);
    expect(fn () => app(SwampHandshakes::class)->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies))->toThrow(SwampReadBusy::class);

    $this->travel(SwampHandshakes::WindowSeconds)->seconds();
    app(SwampHandshakes::class)->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies);

    expect($this->serve->connects)->toBe(['read-token' => 1]);
});

it('connects everything as before when the ledger cannot be used, with one warning', function (): void {
    $blocker = sys_get_temp_dir().'/orbit-ledger-blocker-'.bin2hex(random_bytes(4));
    file_put_contents($blocker, 'a file where the ledger folder should be');
    app()->instance(SwampHandshakes::class, new SwampHandshakes($blocker.'/ledger.json'));
    Log::spy();
    try {
        for ($index = 0; $index < 30; $index++) {
            app(SwampHandshakes::class)->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies);
        }
        app(SwampHandshakes::class)->connect($this->serve, readEndpoint('run-token'), 15, SwampCaller::Start);
    } finally {
        unlink($blocker);
    }

    expect($this->serve->connects)->toBe(['read-token' => 30, 'run-token' => 1]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'handshake ledger is unavailable'))->atMost()->once();
});

it('replaces a corrupt or oversized ledger instead of reading it', function (string $contents): void {
    $path = sys_get_temp_dir().'/orbit-ledger-'.bin2hex(random_bytes(4)).'/ledger.json';
    mkdir(dirname($path));
    file_put_contents($path, $contents);
    app()->instance(SwampHandshakes::class, new SwampHandshakes($path));

    app(SwampHandshakes::class)->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies);

    $state = json_decode((string) file_get_contents($path), true);
    unlink($path);
    rmdir(dirname($path));
    expect($state['count'])->toBe(1)->and($state['callers'])->toBe(['read:dependencies' => 1]);
})->with([
    'not JSON' => ['{"count": 4'],
    'oversized' => [json_encode(['ends' => 0, 'count' => 999, 'minute' => 0, 'callers' => ['x' => str_repeat('y', 9_000)], 'views_refused' => 0])],
]);

it('never holds views longer than one window, whatever end the ledger names', function (): void {
    $path = sys_get_temp_dir().'/orbit-ledger-'.bin2hex(random_bytes(4)).'/ledger.json';
    mkdir(dirname($path));
    file_put_contents($path, json_encode(['ends' => 99_999_999_999, 'count' => 50, 'minute' => 0, 'callers' => [], 'views_refused' => 0]));
    app()->instance(SwampHandshakes::class, new SwampHandshakes($path));

    try {
        app(SwampHandshakes::class)->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies);
        $wait = null;
    } catch (SwampReadBusy $busy) {
        $wait = $busy->retryAfterSeconds;
    }
    $this->travel(SwampHandshakes::WindowSeconds)->seconds();
    app(SwampHandshakes::class)->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies);

    unlink($path);
    rmdir(dirname($path));
    expect($wait)->toBe(SwampHandshakes::WindowSeconds)->and($this->serve->connects)->toBe(['read-token' => 1]);
});

it('keeps the count when two processes take handshakes at the same time', function (): void {
    $path = sys_get_temp_dir().'/orbit-ledger-'.bin2hex(random_bytes(4)).'/ledger.json';
    $children = [];
    foreach ([1, 2] as $_) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $ledger = new SwampHandshakes($path);
            $socket = new HandshakeCountingServe(limit: 1_000);
            for ($index = 0; $index < 200; $index++) {
                $ledger->connect($socket, readEndpoint('run-token'), 15, SwampCaller::Start);
            }
            posix_kill(getmypid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $state = json_decode((string) file_get_contents($path), true);
    unlink($path);
    rmdir(dirname($path));
    expect($state['count'])->toBe(400)->and($state['callers'])->toBe(['run:start' => 400]);
});

it('logs the handshakes of a minute by token and caller when the next minute begins', function (): void {
    $this->travelTo(now()->startOfMinute()->addSeconds(10));
    $ledger = app(SwampHandshakes::class);
    $ledger->connect($this->serve, readEndpoint('run-token'), 15, SwampCaller::Start);
    $ledger->connect($this->serve, readEndpoint('run-token'), 15, SwampCaller::Copy);
    $ledger->connect($this->serve, readEndpoint(), 15, SwampCaller::Reconcile);
    spendWindow($this->serve, SwampHandshakes::ViewShare);
    expect(fn () => $ledger->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies))->toThrow(SwampReadBusy::class);
    $minute = now()->startOfMinute()->toIso8601ZuluString();
    Log::spy();

    $this->travel(1)->minutes();
    $ledger->connect($this->serve, readEndpoint('run-token'), 15, SwampCaller::Start);

    Log::shouldHaveReceived('info')->once()->with('Swamp handshakes per minute', [
        'minute' => $minute, 'run' => 22, 'read' => 1,
        'by_caller' => ['read:reconcile' => 1, 'run:copy' => 1, 'run:start' => 21], 'views_refused' => 1,
    ]);
});
