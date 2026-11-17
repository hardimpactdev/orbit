<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\CiRunnerStateFiles;
use App\Domain\Nodes\RoleName;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Problems\ProblemSource;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\Fleet\StaticCliRelease;
use App\Jobs\Fleet\AdmitFleetRollout;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemFingerprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const RolloutCommit = 'aaaaaaaaaaaa0000000000000000000000000000';
const OtherCommit = 'bbbbbbbbbbbb0000000000000000000000000000';

beforeEach(function (): void {
    // A verified release of the commit, served from the release layout, with its CLI release published.
    $this->base = sys_get_temp_dir().'/orbit-fleet-plan-'.bin2hex(random_bytes(4));
    $release = $this->base.'/releases/'.substr(RolloutCommit, 0, 12);
    mkdir($release.'/apps/gateway', 0700, true);
    file_put_contents($release.'/REVISION', RolloutCommit."\n");
    symlink('releases/'.substr(RolloutCommit, 0, 12), $this->base.'/orbit');
    file_put_contents($this->base.'/cli.json', json_encode(['number' => 9001, 'checksums_url' => 'https://example.test/SHA256SUMS',
        'assets' => [['platform' => 'linux-x86_64', 'url' => 'https://example.test/orbit', 'sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    config(['fleet.rollout' => true, 'app.version' => RolloutCommit, 'orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway']);
    $static = new StaticCliRelease($this->base.'/cli.json');
    app()->instance(ReleaseHistory::class, $static);
    app()->instance(CliReleaseCatalog::class, $static);
    app()->instance(FleetServingRelease::class, new FleetServingRelease($release.'/apps/gateway'));
    GatewayRelease::query()->create(['sha' => RolloutCommit, 'trigger' => 'auto', 'outcome' => 'verified',
        'phases' => ['verify' => ['version' => RolloutCommit]], 'duration_ms' => 0]);

    // Risk order: the app-dev Node first, then the database Node, then the app-prod Node.
    $this->nodes = [];
    foreach (['dev' => RoleName::AppDev, 'db' => RoleName::Database, 'prod' => RoleName::AppProd] as $name => $role) {
        $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => '192.0.2.'.(20 + count(test()->nodes)), 'user' => 'orbit',
            'wireguard_ip' => '10.44.0.'.(20 + count(test()->nodes)), 'ssh_host_fingerprint' => 'SHA256:'.$name,
            'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
        $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);
        $this->nodes[$name] = $node->id;
    }
    $this->cli = app(DesiredFleetState::class)->current()->cli->version;
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

/**
 * One terminal rollout run that ended `$minutes` ago, with each named Node's outcome in admitted order.
 *
 * @param  array<string, string>  $outcomes
 */
function rolloutRun(array $outcomes, int $minutes, string $commit = RolloutCommit, ?string $cli = null, string $code = 'cli.install_failed'): Operation
{
    $nodes = [];
    foreach ($outcomes as $name => $outcome) {
        $nodes[] = ['node_id' => test()->nodes[$name], 'outcome' => $outcome, 'error_code' => $outcome === 'failed' ? $code : null,
            'cli_version' => in_array($outcome, ['converged', 'unchanged'], true) ? ($cli ?? test()->cli) : null,
            'agent_version' => null, 'step' => $outcome === 'failed' ? 'cli' : 'verify', 'ok' => $outcome !== 'failed' && $outcome !== 'pending'];
    }
    $failed = in_array('failed', $outcomes, true);

    return Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null,
        'status' => $failed ? 'failed' : 'succeeded', 'input' => ['commit' => $commit, 'nodes' => array_map(static fn (array $node): array => ['node_id' => $node['node_id']], $nodes)],
        'result' => ['nodes' => $nodes, ...($failed ? ['error_code' => $code] : [])], 'finished_at' => now()->subMinutes($minutes)]);
}

function plannedNames(): array
{
    $plan = app(FleetRolloutPlanAction::class)->execute();
    expect($plan['status'])->toBe('ready');

    return array_map(static fn (int $id): string => array_search($id, test()->nodes, true), $plan['targets']);
}

it('plans every Node of the rollout set in risk order before any visit', function (): void {
    expect(plannedNames())->toBe(['dev', 'db', 'prod']);
});

it('starts again at the failed Node five minutes after its run, and holds back later Nodes until then', function (): void {
    rolloutRun(['dev' => 'converged', 'db' => 'failed', 'prod' => 'pending'], minutes: 3);

    // The failed Node waits, and the Node after it waits too, so no later Node goes before the failed one.
    expect(plannedNames())->toBe([]);

    $this->travel(2)->minutes();
    expect(plannedNames())->toBe(['db', 'prod']);
});

it('lets later Nodes pass a deferred Node, as the run does', function (): void {
    rolloutRun(['dev' => 'deferred', 'db' => 'converged', 'prod' => 'pending'], minutes: 3);

    expect(plannedNames())->toBe(['prod']);
});

it('keeps an earlier visit when a later run did not reach the Node', function (): void {
    rolloutRun(['dev' => 'unchanged', 'db' => 'converged', 'prod' => 'converged'], minutes: 30);
    rolloutRun(['dev' => 'failed', 'db' => 'pending', 'prod' => 'pending'], minutes: 10);

    expect(plannedNames())->toBe(['dev']);
});

it('counts only visits of the desired commit, and checks CLI drift only for CI callers', function (): void {
    Node::query()->findOrFail($this->nodes['dev'])->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    rolloutRun(['dev' => 'converged', 'db' => 'converged', 'prod' => 'converged'], minutes: 30, commit: OtherCommit);
    expect(plannedNames())->toBe(['dev', 'db', 'prod']);

    rolloutRun(['dev' => 'converged', 'db' => 'converged', 'prod' => 'converged'], minutes: 20, cli: '0.1.0');
    expect(plannedNames())->toBe(['dev']);
});

it('alerts once per Node, commit and error code across retries', function (): void {
    $alerts = new class implements ReleaseAlertNotifier
    {
        /** @var list<ReleaseAlert> */
        public array $sent = [];

        public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
        {
            $this->sent[] = $alert;

            return new ReleaseAlertReceipt('request', null, ReleaseAlertStep::skipped('test'), ReleaseAlertStep::skipped('test'));
        }
    };
    app()->instance(ReleaseAlertNotifier::class, $alerts);
    $failed = static fn (Operation $operation, string $code): mixed => app(FleetRolloutAlerts::class)->failed($operation,
        ['node_id' => test()->nodes['db'], 'step' => 'cli', 'error_code' => $code]);

    $failed(rolloutRun(['dev' => 'converged', 'db' => 'failed'], minutes: 20), 'cli.install_failed');
    $failed(rolloutRun(['db' => 'failed'], minutes: 10), 'cli.install_failed');
    $failed(rolloutRun(['db' => 'failed'], minutes: 5, code: 'agent.restart_failed'), 'agent.restart_failed');

    expect(array_map(static fn (ReleaseAlert $alert): string => $alert->incident->key($alert->kind), $alerts->sent))->toBe([
        'rollout_halted|fleet-node:'.$this->nodes['db'].'|'.RolloutCommit.'|cli.install_failed',
        'rollout_halted|fleet-node:'.$this->nodes['db'].'|'.RolloutCommit.'|agent.restart_failed',
    ]);
});

it('resolves a failed Node problem once a later rollout visit converges the Node', function (): void {
    $problem = new ProblemFingerprint(['source' => ProblemSource::Release, 'filed_at' => now()->subMinutes(30), 'evidence' => [
        'release_kind' => 'rollout_halted', 'incident_resource' => 'fleet-node:'.$this->nodes['db'], 'incident_attempt' => RolloutCommit,
        'incident_fault' => 'cli.install_failed']]);
    rolloutRun(['dev' => 'converged', 'db' => 'failed'], minutes: 40);
    rolloutRun(['db' => 'failed'], minutes: 20);
    expect(app(ProblemRecovery::class)->reason($problem))->toBeNull();

    // A run that converged the Node and was cancelled at a later Node still proves it.
    $run = rolloutRun(['db' => 'converged', 'prod' => 'pending'], minutes: 5);
    $run->update(['status' => 'cancelled']);
    expect(app(ProblemRecovery::class)->reason($problem))->toBe('verified_node_convergence');
});

it('refuses a rollout while an earlier one runs, also one admitted without a lock key', function (): void {
    $gateway = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($gateway);
    Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'fleet_rollout', 'target_id' => 668, 'node_id' => null,
        'status' => 'running', 'input' => ['rollout_id' => 668, 'commit' => RolloutCommit, 'nodes' => []]]);

    expect(fn () => app(AdmitFleetRolloutAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.rollout_busy'));
});

it('reports a lagging Node to Doctor from the same visits', function (): void {
    rolloutRun(['dev' => 'converged', 'db' => 'failed'], minutes: 10);
    $lag = app(FleetReleaseLag::class);

    expect($lag->observe(Node::query()->findOrFail($this->nodes['dev'])))->toBeNull()
        ->and($lag->observe(Node::query()->findOrFail($this->nodes['db'])))->toBe(['expected' => substr(RolloutCommit, 0, 12), 'observed' => 'failed'])
        ->and($lag->observe(Node::query()->findOrFail($this->nodes['prod'])))->toBe(['expected' => substr(RolloutCommit, 0, 12), 'observed' => 'no visit yet']);
});

it('waits a minute when only CI callers need an unpublished CLI, and when a rollout runs', function (): void {
    foreach ($this->nodes as $id) {
        Node::query()->findOrFail($id)->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    }
    unlink($this->base.'/cli.json');
    Cache::flush();
    $job = new AdmitFleetRollout()->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased(60);

    file_put_contents($this->base.'/cli.json', json_encode(['number' => 9001, 'checksums_url' => 'https://example.test/SHA256SUMS',
        'assets' => [['platform' => 'linux-x86_64', 'url' => 'https://example.test/orbit', 'sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    Cache::flush();
    $gateway = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($gateway);
    Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'fleet', 'target_id' => $gateway->id, 'node_id' => null,
        'status' => 'running', 'input' => ['commit' => RolloutCommit, 'nodes' => []]]);
    $job = new AdmitFleetRollout()->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased(60);
});

it('admits nothing and ends quietly when the rollout is off', function (): void {
    config(['fleet.rollout' => false]);
    $job = new AdmitFleetRollout()->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect(Operation::query()->where('kind', 'fleet.rollout')->exists())->toBeFalse();
});

it('plans non-CLI Nodes while a CI caller waits for release publication', function (): void {
    Node::query()->findOrFail($this->nodes['dev'])->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    unlink($this->base.'/cli.json');
    Cache::flush();

    expect(plannedNames())->toBe(['db', 'prod']);
});

it('plans all workload Nodes without a CLI release', function (): void {
    unlink($this->base.'/cli.json');
    Cache::flush();

    expect(plannedNames())->toBe(['dev', 'db', 'prod']);
});

it('revisits a successful no-CLI Node when it gains a CI caller', function (): void {
    $visit = rolloutRun(['dev' => 'converged', 'db' => 'converged', 'prod' => 'converged'], minutes: 20);
    $nodes = $visit->result['nodes'];
    $nodes[0]['cli_version'] = null;
    $visit->update(['result' => ['nodes' => $nodes]]);
    Node::query()->findOrFail($this->nodes['dev'])->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);

    expect(plannedNames())->toBe(['dev']);
});

it('retains the minute retry after partial admission and admits CI callers when their CLI appears', function (): void {
    Queue::fake();
    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($gateway);
    config(['swamp.run_token' => 'test-token']);
    foreach ($this->nodes as $id) {
        Node::query()->findOrFail($id)->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned'])->saveQuietly();
    }
    file_put_contents($this->base.'/agent.json', json_encode(['commit' => RolloutCommit, 'version' => '1.2.3',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->base.'/agent.json', '/unused'));
    Node::query()->findOrFail($this->nodes['dev'])->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    $catalog = file_get_contents($this->base.'/cli.json');
    unlink($this->base.'/cli.json');
    Cache::flush();

    $job = new AdmitFleetRollout()->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(60);
    $partial = Operation::query()->where('kind', 'fleet.rollout')->sole();
    expect(array_column($partial->input['nodes'], 'node_id'))->toBe([$this->nodes['db'], $this->nodes['prod']]);
    expect(array_column($partial->input['nodes'], 'release'))->toBe([null, null]);
    $partial->update(['status' => 'succeeded', 'lock_key' => null]);
    rolloutRun(['db' => 'converged', 'prod' => 'converged'], minutes: 10);
    file_put_contents($this->base.'/cli.json', $catalog);
    Cache::flush();

    $retry = new AdmitFleetRollout()->withFakeQueueInteractions();
    app()->call([$retry, 'handle']);

    $retry->assertNotReleased();
    $next = Operation::query()->where('kind', 'fleet.rollout')->latest('id')->firstOrFail();
    expect(array_column($next->input['nodes'], 'node_id'))->toBe([$this->nodes['dev']]);
    expect($next->input['nodes'][0]['release']['url'])->toBe('https://example.test/orbit');
});

it('refuses host-wide input changes before starting a delivery or a role converge', function (): void {
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'pending', 'nodes' => []]]]);
    Node::query()->findOrFail($this->nodes['dev'])->forceFill(['release_inputs' => ['host' => str_repeat('c', 64)]])->save();

    app(ContinueReleaseFleetAction::class)->execute($release);

    $fleet = $release->refresh()->phases['fleet'];
    expect($fleet['status'])->toBe('failed')
        ->and($fleet['nodes'][$this->nodes['dev']]['error_code'])->toBe('fleet.host_inputs_changed')
        ->and($fleet['nodes'][$this->nodes['db']]['status'])->toBe('pending')
        ->and(Operation::query()->count())->toBe(0);
});

it('preserves every prior host approval before an early halt and across a superseding release', function (): void {
    $previous = $this->base.'/releases/'.substr(OtherCommit, 0, 12);
    $resource = '/apps/gateway/resources/machine-software/recipe.json';
    mkdir(dirname($previous.$resource), 0700, true);
    copy(dirname(base_path(), 2).$resource, $previous.$resource);

    $release = GatewayRelease::query()->sole();
    $release->update(['previous_release_id' => substr(OtherCommit, 0, 12), 'phases' => [
        ...$release->phases, 'fleet' => ['status' => 'pending', 'nodes' => []],
    ]]);
    $action = app(ContinueReleaseFleetAction::class);
    $action->execute($release);

    $inputs = app(ReleaseNodeInputs::class);
    $old = $inputs->host($previous);
    expect($old)->not->toBe($inputs->host())
        ->and($release->refresh()->phases['fleet']['nodes'][$this->nodes['dev']]['error_code'])->toBe('fleet.host_inputs_changed')
        ->and(Node::query()->whereKey(array_values($this->nodes))->get()->map(fn (Node $node) => $node->release_inputs['host'])->all())
        ->toBe([$old, $old, $old]);

    // The next release's immediate predecessor carries the unapproved change.
    $matching = str_repeat('c', 12);
    symlink(dirname(base_path(), 2), $this->base.'/releases/'.$matching);
    $next = GatewayRelease::query()->create(['sha' => str_repeat('d', 40), 'trigger' => 'auto', 'outcome' => 'verified',
        'previous_release_id' => $matching, 'phases' => ['fleet' => ['status' => 'pending', 'nodes' => []]], 'duration_ms' => 0]);
    Node::query()->findOrFail($this->nodes['dev'])->update(['status' => LifecycleStatus::Failed]);
    $action->execute($next);
    expect($next->refresh()->phases['fleet']['nodes'][$this->nodes['db']]['error_code'])->toBe('fleet.host_inputs_changed')
        ->and(Operation::query()->count())->toBe(0);
});

it('detects membership, agent pin drift and late CLI publication on the daily pass after fleet current', function (string $change): void {
    Queue::fake();
    $this->travelTo(now('Europe/Amsterdam')->setTime(3, 5));
    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($gateway);
    config(['swamp.run_token' => 'test-token']);
    file_put_contents($this->base.'/agent.json', json_encode(['commit' => RolloutCommit, 'version' => '1.2.3',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));
    $delivery = new AgentDelivery($this->base.'/agent.json', '/unused');
    app()->instance(AgentDelivery::class, $delivery);
    $dev = Node::query()->findOrFail($this->nodes['dev']);
    if ($change === 'late CLI') {
        $dev->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    }
    $inputs = app(ReleaseNodeInputs::class);
    $rows = [];
    foreach (Node::query()->whereKey([...array_values($this->nodes), $gateway->id])->get() as $node) {
        $node->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned', 'release_inputs' => [
            'host' => $inputs->host(), 'agent' => $delivery->pin($node), 'cli' => $node->id === $dev->id && $change === 'late CLI' ? '0.9000.0' : null,
            'roles' => array_map(fn (array $role): string => $role['digest'], $inputs->roles($node)),
        ]])->saveQuietly();
        $rows[$node->id] = ['node_id' => $node->id, 'name' => $node->name, 'status' => 'current'];
    }
    if ($change === 'membership') {
        unset($rows[$dev->id]);
        $dev->refresh()->forceFill(['release_inputs' => null])->saveQuietly();
    } elseif ($change === 'agent drift') {
        app(AgentReportedVersions::class)->record($dev->id, '1.2.3+0000000000000000');
    }
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'current', 'nodes' => $rows]]]);

    $job = new AdmitFleetRollout()->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased(10);

    $operation = Operation::query()->where('kind', 'fleet.rollout')->sole();
    expect(array_column($operation->input['nodes'], 'node_id'))->toBe([$dev->id])
        ->and($release->refresh()->phases['fleet']['status'])->toBe('pending')
        ->and($release->phases['fleet']['nodes'][$dev->id]['delivery_operation_id'])->toBe($operation->id);
    if ($change === 'late CLI') {
        expect($operation->input['nodes'][0]['release']['version'])->toBe($this->cli);
    }
})->with(['membership', 'agent drift', 'late CLI']);

it('fingerprints a state.py-only change on the CI runner and leaves another role stable', function (): void {
    $node = Node::query()->findOrFail($this->nodes['dev']);
    $runner = $node->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    $dev = $node->roles()->where('role', RoleName::AppDev)->sole();
    $path = $this->base.'/role-resources';
    mkdir($path.'/ci-runner', 0700, true);
    foreach (['state.py', 'hold.py'] as $name) {
        copy(resource_path('ci-runner/'.$name), $path.'/ci-runner/'.$name);
    }
    app()->instance(CiRunnerStateFiles::class, new CiRunnerStateFiles($path.'/ci-runner'));
    $inputs = app(ReleaseNodeInputs::class);
    $before = $inputs->roles($node);
    file_put_contents($path.'/ci-runner/state.py', "\n# Test a different deployed script.\n", FILE_APPEND);
    $after = $inputs->roles($node);

    expect($after[$runner->id]['digest'])->not->toBe($before[$runner->id]['digest'])
        ->and($after[$dev->id]['digest'])->toBe($before[$dev->id]['digest']);
});
