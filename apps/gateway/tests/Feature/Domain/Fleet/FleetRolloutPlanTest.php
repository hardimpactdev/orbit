<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\CliReleaseUnavailableReason;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NestedFleetRefresh;
use App\Domain\Metrics\ExporterSelection;
use App\Domain\Metrics\ExporterSelectionReason;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Domain\Nodes\CiRunnerStateFiles;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Problems\ProblemSource;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\Fleet\StaticCliRelease;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Jobs\Fleet\AdmitFleetRollout;
use App\Models\Cluster;
use App\Models\GatewayRelease;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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

it('holds only the Node whose host inputs changed, before its delivery or role converge, and goes on to the next Node', function (): void {
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'pending', 'nodes' => []]]]);
    Node::query()->findOrFail($this->nodes['dev'])->forceFill(['release_inputs' => ['host' => str_repeat('c', 64)]])->save();

    app(ContinueReleaseFleetAction::class)->execute($release);

    // This fixture has no serving Gateway Node, so each later Node gets past its host check and stops at its delivery admission.
    $fleet = $release->refresh()->phases['fleet'];
    expect($fleet['status'])->toBe('failed')
        ->and($fleet['nodes'][$this->nodes['dev']]['error_code'])->toBe('fleet.host_inputs_changed')
        ->and($fleet['nodes'][$this->nodes['dev']])->not->toHaveKey('agent')
        ->and($fleet['nodes'][$this->nodes['db']]['error_code'])->toBe('fleet.rollout_not_ready')
        ->and($fleet['nodes'][$this->nodes['prod']]['error_code'])->toBe('fleet.rollout_not_ready')
        ->and(Operation::query()->count())->toBe(0);
});

it('recognizes a Node\'s approval of an earlier release tree, and still holds it when its own host inputs changed since', function (string $changed, bool $held): void {
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'pending', 'nodes' => []]]]);
    // The previous release's tree, which differs from this one in one host input.
    $previous = $this->base.'/releases/bbbbbbbbbbbb';
    copyRolloutHostTree($previous);
    file_put_contents($previous.'/'.$changed, "\n# the previous release\n", FILE_APPEND);
    $inputs = app(ReleaseNodeInputs::class);
    $dev = Node::query()->findOrFail($this->nodes['dev']);
    expect($inputs->legacyHost($previous))->not->toBe($inputs->legacyHost());
    $dev->forceFill(['release_inputs' => ['host' => $inputs->legacyHost($previous)]])->save();

    app(ContinueReleaseFleetAction::class)->execute($release);

    // Past the host check, the Node stops at its delivery admission: this fixture has no serving Gateway Node.
    expect($release->refresh()->phases['fleet']['nodes'][$dev->id]['error_code'])->toBe($held ? 'fleet.host_inputs_changed' : 'fleet.rollout_not_ready')
        ->and($dev->refresh()->release_inputs['host'])->toBe($held ? $inputs->legacyHost($previous) : $inputs->host(node: $dev));
})->with([
    'a Gateway-only change' => ['swamp/ansible/tasks/gateway-runtime.yml', false],
    'a shared host input change' => ['swamp/ansible/tasks/node-kernel-policy.yml', true],
]);

it('schedules app-prod when any file its production release host reads changes, the staged files beside the Ansible tree included', function (): void {
    $root = $this->base.'/tree';
    $repository = dirname(base_path(), 2);
    copyRolloutHostTree($root);
    foreach (['swamp/ansible', 'swamp/scripts', 'swamp/extensions/behaviors/operations'] as $folder) {
        File::copyDirectory($repository.'/'.$folder, $root.'/'.$folder);
    }
    $tasks = (string) file_get_contents($root.'/swamp/ansible/tasks/production-release-host.yml');
    preg_match_all('~(?<![\w/.-])files/[\w.-]+~', $tasks, $files);
    preg_match_all('~\{\{ playbook_dir \}\}/((?:module_utils|files)/[\w.-]+)~', $tasks, $modules);
    $read = [...array_map(static fn (string $file): string => 'swamp/ansible/'.$file, [...$files[0], ...$modules[1]]),
        'swamp/ansible/tasks/production-release-host.yml'];
    // The role files a converge stages beside the Ansible tree (converge_input.ts) count in the app-prod digest too.
    preg_match('~export const roleSiblings = \[(.*?)\] as const;~s', (string) file_get_contents($root.'/swamp/extensions/behaviors/operations/ansible-input/converge_input.ts'), $list);
    preg_match_all('~"([^"]+)"~', $list[1], $siblings);
    $siblings = array_map(static fn (string $file): string => 'swamp/'.$file, $siblings[1]);
    expect($siblings)->toHaveCount(4)->and(array_slice(ReleaseNodeInputs::ProductionReleaseHostFiles, 0, 4))->toBe($siblings);
    $read = [...$read, ...$siblings];
    expect($read)->toContain('swamp/scripts/production-release-phase', 'swamp/ansible/files/instance-release-vp.ts');
    $inputs = app(ReleaseNodeInputs::class);
    $prod = Node::query()->findOrFail($this->nodes['prod']);
    $before = $inputs->roles($prod, root: $root);

    foreach (array_unique($read) as $file) {
        $original = (string) file_get_contents($root.'/'.$file);
        file_put_contents($root.'/'.$file, "\n# changed\n", FILE_APPEND);
        expect($inputs->roles($prod, root: $root))->not->toBe($before, $file);
        file_put_contents($root.'/'.$file, $original);
    }
});

it('counts the Gateway runtime only as a host input of the Gateway Node', function (): void {
    $root = $this->base.'/tree';
    copyRolloutHostTree($root);
    foreach (['apps/gateway/app/Domain/Nodes/GatewayRuntimeFiles.php', 'swamp/ansible/tasks/gateway-runtime.yml'] as $file) {
        File::ensureDirectoryExists(dirname($root.'/'.$file));
        File::copy(dirname(base_path(), 2).'/'.$file, $root.'/'.$file);
    }
    $inputs = app(ReleaseNodeInputs::class);
    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $dev = Node::query()->findOrFail($this->nodes['dev']);
    [$devHost, $gatewayHost] = [$inputs->host($root, $dev), $inputs->host($root, $gateway)];

    file_put_contents($root.'/swamp/ansible/tasks/gateway-runtime.yml', "\n# changed\n", FILE_APPEND);

    expect($inputs->host($root, $dev))->toBe($devHost)
        ->and($inputs->host($root, $gateway))->not->toBe($gatewayHost);
});

it('keeps a Node\'s baseline recorded before host inputs were classified by Node, and records its own digest', function (): void {
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'pending', 'nodes' => []]]]);
    $inputs = app(ReleaseNodeInputs::class);
    foreach ($this->nodes as $id) {
        Node::query()->findOrFail($id)->forceFill(['release_inputs' => ['host' => $inputs->legacyHost()]])->save();
    }

    app(ContinueReleaseFleetAction::class)->execute($release);

    // Past the host check, each Node stops at its delivery admission: this fixture has no serving Gateway Node.
    $dev = Node::query()->findOrFail($this->nodes['dev']);
    expect($release->refresh()->phases['fleet']['nodes'][$dev->id]['error_code'])->toBe('fleet.rollout_not_ready')
        ->and($dev->release_inputs['host'])->toBe($inputs->host(node: $dev))
        ->and($dev->release_inputs['host'])->not->toBe($inputs->legacyHost());
});

function copyRolloutHostTree(string $path): void
{
    $root = dirname(base_path(), 2);
    $recipe = '/apps/gateway/resources/machine-software/recipe.json';
    File::ensureDirectoryExists(dirname($path.$recipe));
    File::copy($root.$recipe, $path.$recipe);
    foreach (['/apps/gateway/app/Domain/Nodes', '/swamp/ansible/tasks'] as $folder) {
        File::copyDirectory($root.$folder, $path.$folder);
    }
}

it('requires host approval for a network child task change before fleet work or default refresh', function (?string $child): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    $previous = $this->base.'/releases/'.substr(OtherCommit, 0, 12);
    copyRolloutHostTree($previous);
    $inputs = app(ReleaseNodeInputs::class);
    expect($inputs->host($previous))->toBe($inputs->host());

    file_put_contents($this->base.'/agent.json', json_encode(['commit' => RolloutCommit, 'version' => '1.2.3',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));
    $delivery = new AgentDelivery($this->base.'/agent.json', '/unused');
    app()->instance(AgentDelivery::class, $delivery);
    $dev = Node::query()->findOrFail($this->nodes['dev']);
    $rolesBefore = $inputs->roles($dev, root: $previous);
    if ($child !== null) {
        file_put_contents($previous.'/swamp/ansible/tasks/'.$child.'.yml', "\n# Change just this included network task.\n", FILE_APPEND);
    }
    expect($inputs->roles($dev, root: $previous))->toBe($rolesBefore);

    foreach (Node::query()->whereKey(array_values($this->nodes))->get() as $node) {
        $node->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned', 'release_inputs' => [
            'agent' => $delivery->pin($node),
            'roles' => array_map(static fn (array $role): string => $role['digest'], $inputs->roles($node)),
        ]])->saveQuietly();
    }
    $release = GatewayRelease::query()->sole();
    $release->update(['previous_release_id' => substr(OtherCommit, 0, 12), 'phases' => [
        ...$release->phases, 'fleet' => ['status' => 'pending', 'nodes' => []],
    ]]);

    app()->call([new AdmitFleetRollout($release->id), 'handle']);

    $fleet = $release->refresh()->phases['fleet'];
    expect(Operation::query()->count())->toBe(0);
    if ($child === null) {
        expect($fleet['status'])->toBe('current');
        expect($release->phases['default_refresh'])->toBe(['status' => 'current', 'defaults' => []])
            ->and($release->phases['seed_publication']['status'])->toBe('not_required');
    } else {
        expect($fleet['status'])->toBe('failed')
            ->and($fleet['nodes'][$dev->id]['error_code'])->toBe('fleet.host_inputs_changed')
            ->and($dev->refresh()->release_inputs['host'])->toBe($inputs->host($previous));
        expect($release->phases)->not->toHaveKey('default_refresh');
    }
})->with([
    'unchanged control' => [null],
    'hub move' => ['node-converge-hub-move'],
    'address repair' => ['node-converge-address'],
    'hub switch' => ['node-converge-hub-switch'],
    'hub LAN proof' => ['node-hub-lan-proof'],
    'hub peer check' => ['node-converge-hub-peers'],
    'peer handshake' => ['node-converge-handshake'],
    'hub prune through the hub task' => ['node-converge-hub-prune'],
    'absent peer filter through the hub task' => ['node-converge-hub-filter-absent'],
    'hub source through the hub task' => ['node-hub-source'],
]);

it('preserves every prior host approval before an early halt and across a superseding release', function (): void {
    $previous = $this->base.'/releases/'.substr(OtherCommit, 0, 12);
    copyRolloutHostTree($previous);
    file_put_contents($previous.'/swamp/ansible/tasks/node-kernel-policy.yml', "\n# Earlier unapproved host change.\n", FILE_APPEND);

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
    $nextCommit = str_repeat('d', 40);
    $nextPath = 'releases/'.substr($nextCommit, 0, 12);
    mkdir($this->base.'/'.$nextPath.'/apps/gateway', 0700, true);
    file_put_contents($this->base.'/'.$nextPath.'/REVISION', $nextCommit."\n");
    symlink($nextPath, $this->base.'/orbit-next');
    rename($this->base.'/orbit-next', $this->base.'/orbit');
    app()->instance(FleetServingRelease::class, new FleetServingRelease($this->base.'/'.$nextPath.'/apps/gateway'));
    $next = GatewayRelease::query()->create(['sha' => $nextCommit, 'trigger' => 'auto', 'outcome' => 'verified',
        'previous_release_id' => $matching, 'phases' => ['fleet' => ['status' => 'pending', 'nodes' => []]], 'duration_ms' => 0]);
    Node::query()->findOrFail($this->nodes['dev'])->update(['status' => LifecycleStatus::Failed]);
    $action->execute($next);
    expect($next->refresh()->phases['fleet']['nodes'][$this->nodes['db']]['error_code'])->toBe('fleet.host_inputs_changed')
        ->and(Operation::query()->count())->toBe(0);
});

it('serializes fleet work and continues recorded refresh when rollout is disabled', function (): void {
    Queue::fake();
    config(['fleet.rollout' => false, 'instances.nested_fleet_refresh' => true]);
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'disabled', 'nodes' => []]]]);
    $fleet = app(ContinueReleaseFleetAction::class);
    $lock = app(GatewayReleaseLock::class);

    expect($lock->run(fn (): ?int => $fleet->execute($release)))->toBe(15)
        ->and($release->refresh()->phases)->not->toHaveKey('default_refresh');
    expect($fleet->execute($release))->toBeNull()
        ->and($release->refresh()->phases['fleet']['status'])->toBe('disabled')
        ->and($release->phases['default_refresh']['status'])->toBe('current')
        ->and($release->phases['seed_publication']['status'])->toBe('not_required');
});

it('stops seed continuation when default refresh is turned off', function (): void {
    Queue::fake();
    config(['fleet.rollout' => false, 'instances.nested_fleet_refresh' => false]);
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'disabled', 'nodes' => []],
        'seed_publication' => ['status' => 'pending', 'operation_ids' => [41]]]]);

    expect(app(ContinueReleaseFleetAction::class)->execute($release))->toBeNull()
        ->and($release->refresh()->phases['seed_publication'])->toBe(['status' => 'disabled', 'operation_ids' => [41]]);
});

it('admits every Node of a verified release at once at midday, then refreshes the defaults', function (): void {
    Queue::fake();
    // Midday in Amsterdam: the release fleet has no time window, so the database and app-prod Nodes go now too.
    $this->travelTo(now('Europe/Amsterdam')->setTime(12, 30));
    config(['instances.nested_fleet_refresh' => true]);
    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($gateway);
    config(['swamp.run_token' => 'test-token']);
    file_put_contents($this->base.'/agent.json', json_encode(['commit' => RolloutCommit, 'version' => '1.2.3',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));
    $delivery = new AgentDelivery($this->base.'/agent.json', '/unused');
    app()->instance(AgentDelivery::class, $delivery);
    // One nested-fleet default on the app-dev Node, which the release refresh deploys to the release commit.
    $dev = Node::query()->findOrFail($this->nodes['dev']);
    $dev->update(['settings' => ['nested_fleet' => true]]);
    // A nested-fleet host's role inputs read the machine-software recipe of the served release.
    File::copyDirectory(base_path('resources/machine-software'), config('orbit.gateway_checkout').'/resources/machine-software');
    app(AllocateInstanceBridgeAction::class)->execute($dev);
    $project = Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => '.']);
    $project->forceFill(['nested_fleet' => true, 'repository_identity' => GitRepositoryIdentity::derive($project->repository_url)])->save();
    $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $dev->id, 'name' => 'default', 'checkout_path' => '/srv/tool/default',
        'status' => InstanceState::Active]);
    InstanceRuntime::query()->create(['instance_id' => $default->id, 'node_id' => $dev->id, 'incus_project' => InstancePlacement::NestedProject,
        'container_name' => 'instance-'.$default->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        'power_state' => InstanceRuntimePowerState::Stopped, 'idmap_size' => 101000000]);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(['head' => RolloutCommit, 'files' => [
        '.env.example' => ['mode' => '100644', 'object' => str_repeat('d', 40), 'content' => "APP_NAME=deployed\n"], 'composer.json' => null, 'artisan' => null]]);
    $inputs = app(ReleaseNodeInputs::class);
    foreach (Node::query()->whereKey([...array_values($this->nodes), $gateway->id])->get() as $node) {
        $node->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned', 'release_inputs' => [
            'host' => $inputs->host(node: $node), 'agent' => $node->id === $this->nodes['dev'] ? $delivery->pin($node) : 'old-agent',
            'roles' => array_map(fn (array $role): string => $role['digest'], $inputs->roles($node)),
        ]])->saveQuietly();
    }
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'pending', 'nodes' => []]]]);
    $fleet = app(ContinueReleaseFleetAction::class);

    foreach (['db', 'prod'] as $name) {
        expect($fleet->execute($release))->toBe(10);
        $operation = Operation::query()->where('kind', 'fleet.rollout')->latest('id')->firstOrFail();
        expect(array_column($operation->input['nodes'], 'node_id'))->toBe([$this->nodes[$name]])
            ->and($operation->input['nodes'][0])->not->toHaveKey('daytime')
            ->and($release->refresh()->phases['fleet']['nodes'][$this->nodes[$name]]['status'])->toBe('running');
        $node = Node::query()->findOrFail($this->nodes[$name]);
        $operation->forceFill(['status' => 'succeeded', 'finished_at' => now(), 'result' => ['nodes' => [[
            'node_id' => $node->id, 'outcome' => 'converged', 'error_code' => null, 'cli_version' => null,
            'agent_version' => $delivery->reportedVersion($node), 'step' => 'verify', 'ok' => true]]]])->save();
    }
    $gatewayRun = $fleet->execute($release);
    if ($gatewayRun === 10) {
        // The serving Gateway goes last with its own observer.
        $operation = Operation::query()->where('kind', 'fleet.rollout')->latest('id')->firstOrFail();
        $operation->forceFill(['status' => 'succeeded', 'finished_at' => now(), 'result' => ['nodes' => [[
            'node_id' => $gateway->id, 'outcome' => 'converged', 'error_code' => null, 'cli_version' => null,
            'agent_version' => $delivery->reportedVersion($gateway), 'step' => 'verify', 'ok' => true]]]])->save();
        $gatewayRun = $fleet->execute($release);
    }

    // The pass that makes the fleet current admits the default's deploy, pinned to the release commit, in the same tick.
    $deploy = Operation::query()->where('kind', 'instance.deploy')->sole();
    expect($gatewayRun)->toBe(60)
        ->and($release->refresh()->phases['fleet']['status'])->toBe('current')
        ->and($release->phases['default_refresh'])->toBe(['status' => 'pending', 'defaults' => [$default->id => ['status' => 'pending', 'operation_id' => $deploy->id]]])
        ->and($deploy->target_id)->toBe($default->id)
        ->and($deploy->triggered_by)->toBe(NestedFleetRefresh::Trigger)
        ->and($deploy->input['commit'])->toBe(RolloutCommit);
});

it('continues a release whose fleet phase an older Gateway left deferred', function (): void {
    // Drop with the deferred status after one release.
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'deferred', 'nodes' => []]]]);

    expect(app(ContinueReleaseFleetAction::class)->pending(RolloutCommit)?->id)->toBe($release->id);
});

it('detects membership, agent pin drift and late CLI publication on the daily pass after fleet current', function (string $change): void {
    Queue::fake();
    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($gateway);
    config(['swamp.run_token' => 'test-token']);
    file_put_contents($this->base.'/agent.json', json_encode(['commit' => RolloutCommit, 'version' => '1.2.3',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));
    $delivery = new AgentDelivery($this->base.'/agent.json', '/unused');
    app()->instance(AgentDelivery::class, $delivery);
    $dev = Node::query()->findOrFail($this->nodes['dev']);
    if (in_array($change, ['late CLI', 'missing CLI', 'unverified CLI'], true)) {
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

    if ($change === 'unverified CLI') {
        Cache::flush();
        $this->mock(CliReleaseCatalog::class)->shouldReceive('find')->once()
            ->andReturn(DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::ReleaseUnverified));
        expect(app(ContinueReleaseFleetAction::class)->execute($release))->toBeNull()
            ->and($release->refresh()->phases['fleet']['status'])->toBe('failed')
            ->and($release->phases['fleet']['nodes'][$dev->id]['error_code'])->toBe('fleet.cli_delivery_unavailable')
            ->and(Operation::query()->count())->toBe(0);

        return;
    }

    if ($change === 'missing CLI') {
        $release->forceFill(['created_at' => now()])->save();
        file_put_contents($this->base.'/cli.json', '{"number":9001}');
        Cache::flush();
        $fleet = app(ContinueReleaseFleetAction::class);
        expect($fleet->execute($release))->toBe(60);
        $this->travel(30)->minutes();
        expect($fleet->execute($release))->toBeNull()
            ->and($release->refresh()->phases['fleet']['status'])->toBe('failed')
            ->and($release->phases['fleet']['nodes'][$dev->id]['error_code'])->toBe('fleet.cli_delivery_unavailable')
            ->and(Operation::query()->count())->toBe(0);

        return;
    }

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
})->with(['membership', 'agent drift', 'late CLI', 'missing CLI', 'unverified CLI']);

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

it('fingerprints plan-only Instance host changes without reacting to the bridge admission time', function (string $change): void {
    $node = Node::query()->findOrFail($this->nodes['dev']);
    $runner = $node->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    $dev = $node->roles()->where('role', RoleName::AppDev)->sole();
    config(['instances.container_nodes' => [$node->id]]);
    $node->forceFill(['instance_bridge' => '10.253.1.0/24'])->saveQuietly();
    $recipe = '/resources/machine-software/recipe.json';
    $gateway = $this->base.'/orbit/apps/gateway';
    mkdir(dirname($gateway.$recipe), 0700, true);
    copy(base_path().$recipe, $gateway.$recipe);
    $host = app(InstanceHostIntent::class);
    $inputs = app(ReleaseNodeInputs::class);
    $beforePlan = $host->for($node);
    $before = $inputs->roles($node);

    $this->travel(1)->minute();
    expect($host->for($node)['bridge_admitted_at'])->not->toBe($beforePlan['bridge_admitted_at'])
        ->and($inputs->roles($node))->toBe($before);

    if ($change === 'folders') {
        // The serving recipe supplies the built folder plan. The checkout's raw Instance stanza is unchanged.
        $servingRecipe = json_decode(file_get_contents($gateway.$recipe), true, flags: JSON_THROW_ON_ERROR);
        $servingRecipe['instances']['agent']['t3']['bin']['t3'] = 'bin/t3-fixture';
        file_put_contents($gateway.$recipe, json_encode($servingRecipe, JSON_THROW_ON_ERROR));
    } else {
        $node->forceFill(['settings' => ['nested_fleet' => true]])->saveQuietly();
    }
    $afterPlan = $host->for($node);
    $after = $inputs->roles($node);

    if ($change === 'folders') {
        expect($afterPlan['folders']['agent']['version'])->not->toBe($beforePlan['folders']['agent']['version']);
    } else {
        expect($afterPlan['nested']['image']['alias'])->toStartWith('orbit-instance-nested-')
            ->and($afterPlan['ids'])->toBeGreaterThan($beforePlan['ids']);
    }
    expect($after[$dev->id]['digest'])->not->toBe($before[$dev->id]['digest'])
        ->and($after[$runner->id])->toBe($before[$runner->id]);
})->with(['folders', 'nested image and ranges']);

it('fingerprints edits to a shared play or a dependency of that role', function (string $path, bool $shared): void {
    $root = $this->base.'/playbooks';
    $repo = dirname(base_path(), 2);
    $recipe = 'apps/gateway/resources/machine-software/recipe.json';
    foreach ([$recipe, $path] as $file) {
        mkdir(dirname($root.'/'.$file), 0700, true);
        copy($repo.'/'.$file, $root.'/'.$file);
    }
    $node = Node::query()->findOrFail($this->nodes['dev']);
    $runner = $node->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    $dev = $node->roles()->where('role', RoleName::AppDev)->sole();
    $inputs = app(ReleaseNodeInputs::class);
    $before = $inputs->roles($node, true, null, $root);
    file_put_contents($root.'/'.$path, "\n# Test a different shipped play.\n", FILE_APPEND);
    $after = $inputs->roles($node, true, null, $root);

    expect($after[$runner->id]['digest'])->not->toBe($before[$runner->id]['digest']);
    if ($shared) {
        expect($after[$dev->id]['digest'])->not->toBe($before[$dev->id]['digest']);
    } else {
        expect($after[$dev->id])->toBe($before[$dev->id]);
    }
})->with([
    'converge workflow' => ['swamp/workflows/workflow-operation-node-converge-direct.yaml', true],
    'shipped input behavior' => ['swamp/extensions/behaviors/operations/ansible-input/write.ts', true],
    'shared runtime' => ['swamp/ansible/tasks/node-role-runtime.yml', true],
    'shared runtime check' => ['swamp/ansible/tasks/check-runtime.yml', true],
    'CI seed certificate' => ['swamp/ansible/tasks/proof-seed-certificate.yml', false],
]);

it('fingerprints a shared Instance host helper only for the role that runs it', function (string $path): void {
    $root = $this->base.'/instance-helper';
    $repo = dirname(base_path(), 2);
    $recipe = 'apps/gateway/resources/machine-software/recipe.json';
    foreach ([$recipe, $path] as $file) {
        mkdir(dirname($root.'/'.$file), 0700, true);
        copy($repo.'/'.$file, $root.'/'.$file);
    }
    $dev = Node::query()->findOrFail($this->nodes['dev']);
    config(['instances.container_nodes' => [$dev->id]]);
    $ci = Node::query()->findOrFail($this->nodes['db']);
    $ci->forceFill(['settings' => ['ci' => ['places' => 1]]])->saveQuietly();
    $runner = $ci->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    $database = $ci->roles()->where('role', RoleName::Database)->sole();
    $inputs = app(ReleaseNodeInputs::class);
    $beforeDev = $inputs->roles($dev, root: $root);
    $beforeCi = $inputs->roles($ci, root: $root);
    file_put_contents($root.'/'.$path, "\n# Changed Instance host helper.\n", FILE_APPEND);
    $afterCi = $inputs->roles($ci, root: $root);

    expect($inputs->roles($dev, root: $root))->not->toBe($beforeDev)
        ->and($afterCi[$runner->id])->not->toBe($beforeCi[$runner->id])
        ->and($afterCi[$database->id])->toBe($beforeCi[$database->id]);
})->with([
    'NIC check' => 'swamp/ansible/instance_network.py',
    'nested clone probe' => 'swamp/ansible/instance_nested.py',
]);

it('fingerprints a base play change for the roles whose units it applies', function (RoleName $role): void {
    $root = $this->base.'/base-play';
    $repo = dirname(base_path(), 2);
    $recipe = 'apps/gateway/resources/machine-software/recipe.json';
    $play = 'swamp/ansible/tasks/node-converge-base.yml';
    foreach ([$recipe, $play] as $file) {
        mkdir(dirname($root.'/'.$file), 0700, true);
        copy($repo.'/'.$file, $root.'/'.$file);
    }
    $node = SwampNodeFixture::ensure();
    $node->roles()->create(['role' => RoleName::Database, 'status' => LifecycleStatus::Active]);
    if ($role !== RoleName::Swamp) {
        $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);
    }
    $assignment = $node->roles()->where('role', $role)->sole();
    $database = $node->roles()->where('role', RoleName::Database)->sole();
    $inputs = app(ReleaseNodeInputs::class);
    $before = $inputs->roles($node, root: $root);
    file_put_contents($root.'/'.$play, "\n# Changed unit application.\n", FILE_APPEND);
    $after = $inputs->roles($node, root: $root);

    expect($after[$assignment->id])->not->toBe($before[$assignment->id])
        ->and($after[$database->id])->toBe($before[$database->id]);
})->with([RoleName::Swamp, RoleName::WebSocket]);

it('fingerprints the effective app-dev storage folder for that role only', function (): void {
    $node = Node::query()->findOrFail($this->nodes['dev']);
    $runner = $node->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    $dev = $node->roles()->where('role', RoleName::AppDev)->sole();
    $inputs = app(ReleaseNodeInputs::class);
    $before = $inputs->roles($node);
    $node->forceFill(['settings' => ['apps' => ['path' => '/home/orbit/apps']]])->saveQuietly();
    expect($inputs->roles($node))->toBe($before);

    $node->forceFill(['settings' => ['apps' => ['path' => '/fast/apps']]])->saveQuietly();
    $after = $inputs->roles($node);
    expect($after[$dev->id])->not->toBe($before[$dev->id])
        ->and($after[$runner->id])->toBe($before[$runner->id]);
});

it('fingerprints the Swamp helper tool pins from the selected software plan', function (string $tool): void {
    $node = SwampNodeFixture::ensure();
    $node->roles()->create(['role' => RoleName::Database, 'status' => LifecycleStatus::Active]);
    $swamp = $node->roles()->where('role', RoleName::Swamp)->sole();
    $database = $node->roles()->where('role', RoleName::Database)->sole();
    $root = $this->base.'/recipe-tree';
    copyRolloutHostTree($root);
    $file = $root.'/apps/gateway/resources/machine-software/recipe.json';
    $inputs = app(ReleaseNodeInputs::class);
    $before = $inputs->roles($node, root: $root);
    $host = $inputs->host($root);

    $recipe = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    if ($tool === 'deno') {
        $recipe['tools'][$tool]['artifacts']['amd64']['sha256'] = str_repeat('a', 64);
    } else {
        $recipe['tools'][$tool]['version'] .= '+fixture1';
    }
    file_put_contents($file, json_encode($recipe, JSON_THROW_ON_ERROR));
    $after = $inputs->roles($node, root: $root);
    expect($after[$swamp->id])->not->toBe($before[$swamp->id])
        ->and($after[$database->id])->toBe($before[$database->id])
        ->and($inputs->host($root))->toBe($host);

    $recipe['tools']['rsync']['version'] .= '+fixture1';
    file_put_contents($file, json_encode($recipe, JSON_THROW_ON_ERROR));
    expect($inputs->host($root))->not->toBe($host);
})->with(['deno', 'unzip']);

it('fingerprints the rendered Metrics plan without the private password or exporter apply intent', function (): void {
    $node = Node::query()->findOrFail($this->nodes['db']);
    $metrics = $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $database = $node->roles()->where('role', RoleName::Database)->sole();
    $ingress = Node::query()->findOrFail($this->nodes['prod']);
    $cluster = Cluster::query()->create(['name' => 'metrics-fixture', 'tld' => 'metrics.test', 'state' => 'active']);
    $ingress->forceFill(['cluster_id' => $cluster->id])->saveQuietly();
    $ingress->roles()->create(['role' => RoleName::Ingress, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);
    $item = new MetricsExporterProjectionItem($ingress, new ExporterSelection(true, ExporterSelectionReason::RoleDefault));
    $this->mock(MetricsExporterProjection::class, fn ($projection) => $projection->shouldReceive('for', 'forNode')
        ->andReturnUsing(fn (...$arguments) => count($arguments) === 1 ? [$item] : $item));
    $route = Route::query()->create(['kind' => 'custom_proxy', 'cluster_id' => $cluster->id,
        'domain' => 'after.metrics.test', 'publication' => 'public', 'provenance' => 'explicit', 'status' => 'pending']);
    $inputs = app(ReleaseNodeInputs::class);
    $before = $inputs->roles($node);

    $route->forceFill(['status' => 'active'])->saveQuietly();
    $after = $inputs->roles($node);

    expect($after[$metrics->id])->not->toBe($before[$metrics->id])
        ->and($after[$database->id])->toBe($before[$database->id]);
    $spec = app(NodeRoleRuntimeInput::class)->capture($node, $metrics, publicOnly: true);
    $runtime = app(NativeNodeRoleRuntimeInput::class)->capture($node, $metrics, ['runtime' => $spec], null, converge: true);
    $plan = $runtime['intent']['metrics']['plan'];
    foreach ($plan['services'] as $service) {
        expect($service['argv'])->toContain($service['image']);
    }
    expect($runtime['execution_secrets'])->toBe([])
        ->and(array_column($runtime['intent']['files'], 'path'))->not->toContain('/etc/orbit/metrics/grafana/admin-password');
});
