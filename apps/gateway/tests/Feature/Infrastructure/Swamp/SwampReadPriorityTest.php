<?php

declare(strict_types=1);

use App\Actions\Instances\Dependencies\ReadSwampDependenciesAction;
use App\Actions\Tasks\ReadTaskSwampRunsAction;
use App\Domain\Instances\Dependencies\SwampDependencyReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Tasks\SwampRunReader;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Dependencies\WebSocketSwampDependencyReader;
use App\Infrastructure\Swamp\SwampCaller;
use App\Infrastructure\Swamp\SwampHandshakes;
use App\Infrastructure\Swamp\SwampReadBusy;
use App\Infrastructure\Tasks\WebSocketSwampRunReader;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
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
        static fn (int $limit): HandshakeCountingServe => $serve, 'swamp.test', 'read-token', '/test/ca.pem', $serve->clock(...)));
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

    // The next poll, a new request inside that wait, answers the same from the hold, and still names the wait.
    app()->forgetScopedInstances();
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
    expect($state['times'])->toHaveCount(1)->and($state['callers'])->toBe(['read:dependencies' => 1]);
})->with([
    'not JSON' => ['{"times": [4'],
    'oversized' => [json_encode(['times' => array_fill(0, 30, 1.5), 'held_until' => 0, 'minute' => 0, 'callers' => ['x' => str_repeat('y', 9_000)], 'views_refused' => 0])],
]);

it('never holds views longer than one window, whatever end the ledger names', function (): void {
    $path = sys_get_temp_dir().'/orbit-ledger-'.bin2hex(random_bytes(4)).'/ledger.json';
    mkdir(dirname($path));
    file_put_contents($path, json_encode(['times' => array_fill(0, 30, 99_999_999_999.0), 'held_until' => 99_999_999_999, 'minute' => 0, 'callers' => [], 'views_refused' => 0]));
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
    // A long lock wait for this test only: under load the 50 ms wait can run out, and that connect then goes ahead uncounted
    // by design (see the next test). With room to wait, a count lost while the lock is held is the only way to miss 400.
    $path = sys_get_temp_dir().'/orbit-ledger-'.bin2hex(random_bytes(4)).'/ledger.json';
    $children = [];
    foreach ([1, 2] as $_) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            // A child never returns into the suite: SIGKILL when all 200 went through, SIGABRT when one threw.
            $signal = SIGABRT;
            try {
                $ledger = new SwampHandshakes($path, lockMilliseconds: 10_000);
                $socket = new HandshakeCountingServe(limit: 1_000);
                for ($index = 0; $index < 200; $index++) {
                    $ledger->connect($socket, readEndpoint('run-token'), 15, SwampCaller::Start);
                }
                $signal = SIGKILL;
            } finally {
                posix_kill(getmypid(), $signal);
            }
        }
        $children[] = $pid;
    }
    $signals = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $signals[] = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null;
    }
    expect($signals)->toBe([SIGKILL, SIGKILL]);

    $state = json_decode((string) file_get_contents($path), true);
    unlink($path);
    rmdir(dirname($path));
    expect($state['callers'])->toBe(['run:start' => 400]);
});

it('connects uncounted, and counts nothing, while another process holds the ledger lock past the wait', function (): void {
    $path = sys_get_temp_dir().'/orbit-ledger-'.bin2hex(random_bytes(4)).'/ledger.json';
    $ledger = new SwampHandshakes($path);
    $ledger->connect($this->serve, readEndpoint('run-token'), 15, SwampCaller::Start);
    $before = (string) file_get_contents($path);
    $holder = fopen($path, 'c+');
    flock($holder, LOCK_EX);
    try {
        $ledger->connect($this->serve, readEndpoint('run-token'), 15, SwampCaller::Start);
        $ledger->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies);
    } finally {
        flock($holder, LOCK_UN);
        fclose($holder);
    }
    $after = (string) file_get_contents($path);
    unlink($path);
    rmdir(dirname($path));

    // Both went ahead, a view too: fail open. The ledger kept only the first, counted handshake.
    expect($this->serve->connects)->toBe(['run-token' => 2, 'read-token' => 1])
        ->and($after)->toBe($before)
        ->and(json_decode($after, true)['callers'])->toBe(['run:start' => 1]);
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

it('never lets views take more than their share of any serve window, wherever serve\'s window starts', function (): void {
    $ledger = app(SwampHandshakes::class);
    $views = [];
    $view = function () use ($ledger, &$views): void {
        try {
            $ledger->connect($this->serve, readEndpoint(), 2, SwampCaller::Dependencies);
            $views[] = now()->getTimestampMs() / 1000;
        } catch (SwampReadBusy) {
        }
    };
    $start = now()->getTimestampMs() / 1000;
    // A start at 0 s and a cancel at 60.5 s; a view at 100 s; views from 121 s to 125 s; views again from 161 s to 170 s.
    $ledger->connect($this->serve, readEndpoint('run-token'), 15, SwampCaller::Start);
    $this->travel(60_500)->milliseconds();
    $ledger->connect($this->serve, readEndpoint('run-token'), 15, SwampCaller::Cancel);
    $this->travel(39_500)->milliseconds();
    $view();
    $this->travel(21)->seconds();
    foreach (range(1, 25) as $index) {
        $view();
        if ($index % 5 === 0) {
            $this->travel(1)->seconds();
        }
    }
    $this->travel(161 - 126)->seconds();
    foreach (range(1, 30) as $index) {
        $view();
        if ($index % 3 === 0) {
            $this->travel(1)->seconds();
        }
    }

    // Count views in every 60 s span that starts at a view: serve's window can start at any of them.
    $worst = max(array_map(static fn (float $from): int => count(array_filter($views, static fn (float $at): bool => $at >= $from && $at < $from + 60)), $views));
    expect($worst)->toBeLessThanOrEqual(SwampHandshakes::ViewShare)
        ->and($views[0] - $start)->toEqual(100);
});

it('holds another Project only when serve answered for it, not when its answer did not come', function (): void {
    $first = readProject($this->serve, 1);
    $second = readProject($this->serve, 2);
    $secondName = 'dependencies-v1-project-'.$second->id.'-'.hash('sha256', 'refs/heads/main');
    $drop = true;
    $this->serve->answer = static function (array $frame) use ($secondName, &$drop): ?bool {
        return $drop && ($frame['payload']['dataName'] ?? null) === $secondName ? false : null;
    };

    expect(readDependencies($first))->toBe('available')
        // The read waited its five seconds for the answer that did not come.
        ->and($this->serve->waited)->toEqualWithDelta(5.0, 0.001);
    $drop = false;

    // The lost answer leaves no hold: the second Project's own read asks serve and finds its index.
    expect(readDependencies($second))->toBe('available')
        ->and($this->serve->connects)->toBe(['read-token' => 2]);
});

it('reads a task group at most once in five seconds while its reads fail, whatever number of screens poll it', function (): void {
    $serve = $this->serve;
    $serve->answer = static fn (array $frame): ?array => $frame['type'] === 'workflow.run.search'
        ? ['type' => 'error', 'id' => $frame['id'], 'payload' => ['message' => 'unavailable']] : null;
    app()->instance(SwampRunReader::class, new WebSocketSwampRunReader($serve, 'swamp.test', 'read-token', '/test/ca.pem'));
    $group = Task::query()->create(['title' => 'Group', 'brief' => 'Read it', 'status' => TaskGroupStatus::Running]);

    $answers = [];
    foreach (range(1, 3) as $_) {
        $answers[] = app(ReadTaskSwampRunsAction::class)->execute($group)->availability;
    }
    $this->travel(5)->seconds();
    $answers[] = app(ReadTaskSwampRunsAction::class)->execute($group)->availability;

    expect($answers)->toBe(['unavailable', 'unavailable', 'unavailable', 'unavailable'])
        ->and($serve->connects)->toBe(['read-token' => 2]);
});
