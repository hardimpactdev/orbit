<?php

declare(strict_types=1);

use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Instances\InstanceState;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\RoleName;
use App\Domain\Proof\ProofCopyHosts;
use App\Domain\Proof\ProofGroup;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

/** @param array<string, mixed> $settings */
function ciRunnersNode(string $name, string $address, array $settings = []): Node
{
    return Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => $address, 'public_ssh_host' => $address, 'user' => 'orbit', 'settings' => $settings]));
}

/**
 * One Prometheus series of the node exporter job.
 *
 * @param  array<string, string>  $labels
 */
function ciRunnersSeries(string $name, string $node, float|int $value, array $labels = []): array
{
    return ['metric' => ['__name__' => $name, 'job' => 'orbit-node-exporter', 'node' => $node, ...$labels], 'value' => [1763294400, (string) $value]];
}

/** A copy of a Project's default that a task group owns, in the nested project. */
function ciRunnersCopy(Node $node, Project $project, string $name): Task
{
    $group = Task::query()->create(['project_id' => $project->id, 'title' => $name, 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'orchestration' => 'external']);
    $copy = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/srv/'.$name,
        'status' => InstanceState::Active, 'branch' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'starting_commit' => str_repeat('a', 40)]);
    InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test',
        'instance_id' => $copy->id, 'incus_project' => 'orbit-instances-nested', 'container_name' => 'instance-'.$copy->id, 'bridge_address' => '10.253.0.'.(10 + $copy->id)]);

    return $group;
}

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-16T12:00:00Z'));
    $this->gateway = ciRunnersNode('gateway', '10.44.0.1');
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $metrics = ciRunnersNode('metrics', '10.44.0.3');
    $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->mock(MetricsCredentialManager::class, fn ($mock) => $mock->shouldReceive('storedCredentials')
        ->andReturn(new MetricsCredentialsData('https://metrics.orbit', 'admin', 'secret')));
    $this->ci = ciRunnersNode('ci-b', '10.44.0.7', ['ci' => ['places' => 3, 'proof_only' => false]]);
    // Its ci-runner converge installed this release's hold script and prepared nested hosting: it takes proof copies.
    $this->ci->forceFill(['instance_bridge' => '10.253.0.0/24', 'ci_hold_digest' => ProofCopyHosts::holdDigest(), 'ci_nested_hosting' => true])->saveQuietly();
    $this->ci->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
    $this->other = ciRunnersNode('ci-a', '10.44.0.8');
    $this->other->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
    ciRunnersNode('app', '10.44.0.9')->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->series = [
        ciRunnersSeries('orbit_ci_runners_observed_timestamp_seconds', 'ci-b', now()->getTimestamp() - 5),
        ciRunnersSeries('orbit_ci_runners_read_ok', 'ci-b', 1),
        ciRunnersSeries('orbit_ci_runner_info', 'ci-b', 1, ['runner' => 'ci-b-2', 'state' => 'stopped', 'service' => 'unknown', 'version' => '']),
        ciRunnersSeries('orbit_ci_runner_info', 'ci-b', 1, ['runner' => 'ci-b-1', 'state' => 'running', 'service' => 'active', 'version' => '2.330.0']),
        ciRunnersSeries('orbit_ci_runner_busy', 'ci-b', 1, ['runner' => 'ci-b-1']),
        ciRunnersSeries('orbit_ci_runner_busy', 'ci-b', 0, ['runner' => 'ci-b-2']),
        ciRunnersSeries('orbit_ci_runners_observed_timestamp_seconds', 'ci-a', now()->getTimestamp() - 300),
        ciRunnersSeries('orbit_ci_runners_read_ok', 'ci-a', 0),
    ];
    $this->jobs = [
        ['id' => 501, 'run_id' => 50, 'name' => 'php (1)', 'labels' => ['self-hosted'], 'runner_name' => 'ci-b-1', 'status' => 'in_progress', 'conclusion' => null,
            'created_at' => '2026-11-16T11:58:00Z', 'started_at' => '2026-11-16T11:59:00Z', 'completed_at' => null, 'html_url' => 'https://github.com/acme/shop/actions/runs/50/job/501'],
        ['id' => 401, 'run_id' => 40, 'name' => 'php (1)', 'labels' => ['self-hosted'], 'runner_name' => 'ci-b-1', 'status' => 'completed', 'conclusion' => 'failure',
            'created_at' => '2026-11-16T10:00:00Z', 'started_at' => '2026-11-16T10:00:10Z', 'completed_at' => '2026-11-16T10:10:10Z', 'html_url' => null],
        ['id' => 402, 'run_id' => 40, 'name' => 'php (2)', 'labels' => ['self-hosted'], 'runner_name' => 'ci-b-1', 'status' => 'completed', 'conclusion' => 'success',
            'created_at' => '2026-11-16T09:00:00Z', 'started_at' => '2026-11-16T09:00:30Z', 'completed_at' => '2026-11-16T09:05:30Z', 'html_url' => null],
        ['id' => 403, 'run_id' => 40, 'name' => 'php (3)', 'labels' => ['self-hosted'], 'runner_name' => 'proof-host', 'status' => 'completed', 'conclusion' => 'timed_out',
            'created_at' => '2026-11-16T08:00:00Z', 'started_at' => '2026-11-16T08:01:00Z', 'completed_at' => '2026-11-16T08:02:00Z', 'html_url' => null],
    ];
    $this->prometheus = fn () => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $this->series]]);
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/api/datasources') => Http::response([['type' => 'prometheus', 'uid' => 'prom']]),
            str_contains($url, '/api/datasources/proxy/uid/prom/api/v1/query') => ($this->prometheus)(),
            str_ends_with($url, '/installation') => Http::response(['id' => 7]),
            str_ends_with($url, '/access_tokens') => Http::response(['token' => 'read-token', 'permissions' => $request->data()['permissions']], 201),
            str_contains($url, '/actions/workflows?') => Http::response(['total_count' => 1, 'workflows' => [['id' => 3, 'path' => '.github/workflows/ci.yml']]]),
            str_contains($url, '/contents/.github/workflows/ci.yml') => Http::response(['encoding' => 'base64',
                'content' => base64_encode("jobs:\n  fixtures:\n    runs-on: [self-hosted, hardimpact]\n")]),
            str_contains($url, '/actions/workflows/3/runs?') => Http::response(['total_count' => 2, 'workflow_runs' => [
                ['id' => 50, 'name' => 'CI', 'status' => 'in_progress', 'conclusion' => null, 'run_attempt' => 1],
                ['id' => 40, 'name' => 'CI', 'status' => 'completed', 'conclusion' => 'failure', 'run_attempt' => 1],
            ]]),
            str_contains($url, '/actions/runs/50/jobs') => Http::response(['total_count' => 1, 'jobs' => [$this->jobs[0]]]),
            str_contains($url, '/actions/runs/40/jobs') => Http::response(['total_count' => 3, 'jobs' => array_slice($this->jobs, 1)]),
            default => Http::response(['message' => 'unexpected '.$url], 418),
        };
    });
});

describe('GET /api/v1/ci/runners', function (): void {
    it('shows each CI Node with its places, resources, runners and the jobs of the window', function (): void {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'orbit', 'orbit', 'acme', 'organization', 'https://github.com/apps/orbit', $pem));
        Project::query()->create(['slug' => 'shop', 'name' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git', 'default_branch' => 'main', 'root' => '.',
            'source_access' => 'github_app']);
        $project = Project::query()->create(['slug' => 'orbit', 'name' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main', 'root' => '.',
            'source_access' => 'gh_cli']);
        $proof = ciRunnersCopy($this->ci, $project, 'task-proof');
        ciRunnersCopy($this->ci, $project, 'task-work');
        Operation::query()->create(['kind' => ProofGroup::OwnerKind, 'target_type' => 'task_group', 'target_id' => $proof->id, 'node_id' => $this->gateway->id,
            'status' => 'running', 'input' => [], 'triggered_by' => 'test']);
        NodeFact::query()->create(['node_id' => $this->ci->id, 'kind' => NodeFactKind::Metrics, 'observed_at' => now()->subSeconds(30), 'digest' => str_repeat('d', 64), 'received_at' => now(), 'data' => [
            'memory' => ['used' => 4_000, 'total' => 16_000], 'pressure' => ['cpu' => ['some_avg10' => 12.5], 'memory' => ['some_avg10' => 0.5]],
            'disks' => [['mount' => '/srv', 'used' => 1, 'total' => 2], ['mount' => '/', 'used' => 300, 'total' => 1_000, 'observed_at' => null, 'stale' => false]],
        ]]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->getJson('/api/v1/ci/runners')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $data = $response->json('data');
        expect($data['generated_at'])->toBe('2026-11-16T12:00:00Z')
            ->and($data['window_hours'])->toBe(48)
            ->and(array_column($data['nodes'], 'node_name'))->toBe(['ci-a', 'ci-b'])
            ->and($data['github_error_code'])->toBeNull()
            ->and($data['repositories'])->toBe([['repository' => 'acme/shop', 'error_code' => null, 'truncated' => false]])
            ->and(array_column($data['jobs'], 'job_id'))->toBe([501, 401, 402, 403]);

        [$unread, $node] = $data['nodes'];
        expect($unread)->toMatchArray(['node_id' => $this->other->id, 'places' => ['total' => null, 'proof_only' => false, 'runner_jobs' => 0, 'proof_copies' => 0, 'free' => null],
            'resources' => null, 'runners' => [], 'proof_copies' => []])
            ->and($unread['runner_state'])->toBe(['observed_at' => '2026-11-16T11:55:00Z', 'stale' => true, 'error_code' => 'ci_runner.state_unavailable']);

        expect($node['places'])->toBe(['total' => 3, 'proof_only' => false, 'runner_jobs' => 1, 'proof_copies' => 1, 'free' => 1])
            ->and($node['proof_copies'])->toBe([['instance_id' => Instance::query()->where('name', 'task-proof')->value('id'), 'instance_name' => 'task-proof', 'task_group_id' => $proof->id]])
            ->and($node['resources'])->toBe(['observed_at' => '2026-11-16T11:59:30Z', 'possibly_outdated' => true, 'cpu_pressure' => 12.5, 'memory_pressure' => 0.5,
                'memory' => ['used_bytes' => 4_000, 'total_bytes' => 16_000], 'disk' => ['used_bytes' => 300, 'total_bytes' => 1_000]])
            ->and($node['runner_state'])->toBe(['observed_at' => '2026-11-16T11:59:55Z', 'stale' => false, 'error_code' => null])
            ->and($node['runners'])->toBe([
                ['name' => 'ci-b-1', 'state' => 'running', 'service' => 'active', 'busy' => true, 'version' => '2.330.0',
                    'current_job' => ['job_id' => 501, 'repository' => 'acme/shop', 'workflow' => 'CI', 'job' => 'php (1)', 'started_at' => '2026-11-16T11:59:00Z',
                        'url' => 'https://github.com/acme/shop/actions/runs/50/job/501'],
                    'stats' => ['jobs' => 2, 'failed' => 1, 'queue_wait_seconds_median' => 20, 'run_seconds_median' => 450, 'busy_seconds' => 900]],
                ['name' => 'ci-b-2', 'state' => 'stopped', 'service' => 'unknown', 'busy' => false, 'version' => null, 'current_job' => null,
                    'stats' => ['jobs' => 0, 'failed' => 0, 'queue_wait_seconds_median' => null, 'run_seconds_median' => null, 'busy_seconds' => 0]],
            ]);

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/v1/query')
            && str_contains((string) $request['query'], 'job="orbit-node-exporter"'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/runners'));
    });

    it('keeps a proof-only Node\'s places for proof copies, while its busy runner still shows', function (): void {
        $this->ci->forceFill(['settings' => ['ci' => ['places' => 3, 'proof_only' => true]]])->saveQuietly();
        $project = Project::query()->create(['slug' => 'orbit', 'name' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main', 'root' => '.',
            'source_access' => 'gh_cli']);
        $proof = ciRunnersCopy($this->ci, $project, 'task-proof');
        Operation::query()->create(['kind' => ProofGroup::OwnerKind, 'target_type' => 'task_group', 'target_id' => $proof->id, 'node_id' => $this->gateway->id,
            'status' => 'running', 'input' => [], 'triggered_by' => 'test']);

        $node = $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->getJson('/api/v1/ci/runners')->assertOk()->json('data.nodes.1');

        expect($node['places'])->toBe(['total' => 3, 'proof_only' => true, 'runner_jobs' => 0, 'proof_copies' => 1, 'free' => 2])
            ->and(array_column($node['runners'], 'busy', 'name'))->toBe(['ci-b-1' => true, 'ci-b-2' => false]);
    });

    it('shows no free place on a Node that takes no proof copies, by the placement rule', function (array $record): void {
        // docs/reference/ci-runner-view.md: `free` never counts a place that a proof does not take.
        $this->ci->forceFill($record)->saveQuietly();

        $node = $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->getJson('/api/v1/ci/runners')->assertOk()->json('data.nodes.1');

        expect($node['places'])->toBe(['total' => 3, 'proof_only' => false, 'runner_jobs' => 1, 'proof_copies' => 0, 'free' => 0]);
    })->with([
        'a hold script of an older release' => [['ci_hold_digest' => str_repeat('0', 64)]],
        'no nested hosting from its converge' => [['ci_nested_hosting' => false]],
    ]);

    it('still answers when Prometheus and the GitHub App cannot', function (): void {
        $this->prometheus = static fn () => Http::response(['message' => 'down'], 502);

        $data = $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->getJson('/api/v1/ci/runners')->assertOk()->json('data');

        expect($data['github_error_code'])->toBe('github.app_missing')
            ->and($data['jobs'])->toBe([])
            ->and(array_column($data['nodes'], 'runner_state'))->toBe([
                ['observed_at' => null, 'stale' => false, 'error_code' => 'ci_runner.state_unavailable'],
                ['observed_at' => null, 'stale' => false, 'error_code' => 'ci_runner.state_unavailable'],
            ])
            ->and($data['nodes'][1]['places'])->toBe(['total' => 3, 'proof_only' => false, 'runner_jobs' => 0, 'proof_copies' => 0, 'free' => 3]);
    });

    it('reads Prometheus once for every viewer of the same ten seconds', function (): void {
        $as = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->getJson('/api/v1/ci/runners')->assertOk();
        $queries = static fn (): int => Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/api/v1/query'))->count();

        $as();
        $as();
        expect($queries())->toBe(1);

        $this->travel(11)->seconds();
        $as();
        expect($queries())->toBe(2);
    });

    it('refuses a caller without Gateway authority', function (): void {
        $caller = ciRunnersNode('laptop', '10.44.0.20');
        $caller->accessibleNodes()->attach($this->ci);

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson('/api/v1/ci/runners')
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        Http::assertNothingSent();
    });
});
