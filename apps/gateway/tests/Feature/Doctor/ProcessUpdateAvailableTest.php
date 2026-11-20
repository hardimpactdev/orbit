<?php

declare(strict_types=1);

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\AgentView\AgentStateView;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Firewall\FirewallInspectionInput;
use App\Domain\Problems\ProblemSource;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\ProcessArtifact;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-20T12:00:00Z'));
    $this->view = new CacheAgentStateView(new Repository(new ArrayStore));
    $this->view->putSubscriber(true, true, 15);
    app()->instance(AgentStateView::class, $this->view);
    Http::preventStrayRequests();
    $this->mock(SshExecutor::class)->shouldNotReceive('execute');
});

afterEach(function (): void {
    ProblemCollectorState::flushEventListeners();
});

/** A services Node whose agent reports the router unit active, and the router Process with an artifact. */
function update_router(?string $version, ?string $latest): array
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'services', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.17', 'public_ssh_host' => '192.0.2.17', 'ssh_host_fingerprint' => 'SHA256:fixture', 'user' => 'orbit',
    ]));
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'ai-router',
        'runtime' => 'systemd', 'desired_state' => 'running', 'status' => 'active', 'runtime_config' => []]);
    ProcessArtifact::query()->create(['process_id' => $process->id, 'source' => ProcessArtifact::SourceGithubRelease, 'repository' => 'router-for-me/CLIProxyAPI',
        'asset' => 'CLIProxyAPI_{version}_linux_amd64.tar.gz', 'binary' => 'cli-proxy-api', 'version' => $version, 'latest_version' => $latest]);
    test()->view->putNode($node->id, [], null, 1, CacheAgentStateView::now(), null);
    foreach (['host' => ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [['mount' => '/', 'size' => 100_000_000_000, 'free' => 50_000_000_000]], 'instance_bridge' => ['error_code' => null]],
        'units' => ['units' => [['runtime' => 'systemd', 'name' => "orbit-process-{$process->id}-ai-router", 'state' => 'active', 'sub' => 'running']], 'awake' => []],
        'ufw' => ['installed' => true, 'enabled' => true, 'rules' => array_column(app(FirewallInspectionInput::class)->expected($node), 'shape')]] as $kind => $data) {
        NodeFact::query()->updateOrCreate(['node_id' => $node->id, 'kind' => $kind], ['digest' => hash('sha256', json_encode($data)), 'data' => $data, 'observed_at' => now(), 'received_at' => now()]);
    }

    return [$node, $process];
}

it('reports an available update as informational, so Doctor stays healthy', function (): void {
    [$node, $process] = update_router('8.0.63', '8.0.80');

    $report = app(FactDoctor::class)->report([$node], [DoctorFamily::Process]);
    $issues = $report->toArray()['nodes'][0]['families'][0]['issues'];

    expect($issues)->toHaveCount(1)
        ->and($issues[0])->toMatchArray(['code' => 'process.update_available', 'kind' => 'informational', 'resource_id' => $process->id,
            'expected' => '8.0.80', 'observed' => '8.0.63'])
        ->and($report->healthy)->toBeTrue()
        ->and($report->summary)->toMatchArray(['drift' => 0, 'unverifiable' => 0, 'informational' => 1]);
});

it('reports no update without a known version or without a newer release', function (?string $version, ?string $latest): void {
    [$node] = update_router($version, $latest);

    $codes = array_column(app(FactDoctor::class)->report([$node], [DoctorFamily::Process])->toArray()['nodes'][0]['families'][0]['issues'], 'code');

    expect($codes)->not->toContain('process.update_available');
})->with([
    'unknown version' => [null, '8.0.80'],
    'not checked yet' => ['8.0.63', null],
    'current' => ['8.0.80', '8.0.80'],
    'newer installed' => ['8.0.81', '8.0.80'],
]);

it('records an available update as a problem, the collector\'s one informational exception, and keeps it open while it lasts', function (): void {
    update_router('8.0.63', '8.0.80');
    config(['orbit.gateway_queue.connection' => '']);

    app(RunDoctorAction::class)->scheduled();
    $this->travel(5)->minutes();
    $this->view->putSubscriber(true, true, 1);
    app(RunDoctorAction::class)->scheduled();

    $problem = ProblemFingerprint::query()->where('source', ProblemSource::Doctor)->where('evidence->doctor_code', 'process.update_available')->sole();
    expect($problem->evidence)->toMatchArray(['doctor_code' => 'process.update_available', 'doctor_family' => 'process'])
        ->and($problem->evidence)->not->toHaveKey('resolved_at')
        ->and($problem->occurrences)->toBe(2);
});

it('resolves the problem once the Process runs the latest release', function (): void {
    [, $process] = update_router('8.0.63', '8.0.80');
    config(['orbit.gateway_queue.connection' => '']);
    app(RunDoctorAction::class)->scheduled();

    ProcessArtifact::query()->where('process_id', $process->id)->update(['version' => '8.0.80']);
    $this->travel(5)->minutes();
    $this->view->putSubscriber(true, true, 1);
    app(RunDoctorAction::class)->scheduled();

    expect(ProblemFingerprint::query()->where('source', ProblemSource::Doctor)->where('evidence->doctor_code', 'process.update_available')->sole()->evidence['resolution'])->toBe('fresh_doctor_success');
});
