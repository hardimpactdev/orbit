<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCommitComparison;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInsights;
use App\Jobs\Tasks\StampDeployedTasks;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(LazilyRefreshDatabase::class);

/** A production Instance of a GitHub Project, and a GitHub whose main branch holds `$history` in order; every read is listed in `$reads`. */
function deployWorld(array $history, array &$reads = [], bool $unreadable = false): Instance
{
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00'));
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'read', 'read', 'hardimpactdev', 'organization', 'https://github.com/apps/read', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($history, &$reads, $unreadable): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReadToken')->andReturn('contents-read');
        $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('pull-requests-read');
        $github->shouldReceive('compareCommits')->andReturnUsing(function (string $token, GitHubRepository $repository, string $base, string $head) use ($history, &$reads, $unreadable): GitHubCommitComparison {
            $reads[] = 'compare:'.$base[0].'...'.$head[0];
            if ($unreadable) {
                throw GitHubApiException::unavailable();
            }
            $at = array_search($base, $history, true);
            $deployed = array_search($head, $history, true);

            return match (true) {
                $base === $head => new GitHubCommitComparison(GitHubComparisonStatus::Identical, $base, $base),
                $at !== false && $deployed !== false && $at < $deployed => new GitHubCommitComparison(GitHubComparisonStatus::Ahead, $base, $base),
                default => new GitHubCommitComparison(GitHubComparisonStatus::Diverged, $base, str_repeat('0', 40)),
            };
        });
        $github->shouldReceive('pullRequest')->andReturnUsing(function (string $token, GitHubRepository $repository, int $number) use (&$reads): GitHubPullRequest {
            $reads[] = 'pull:'.$number;

            return new GitHubPullRequest(GitHubPullRequestState::Merged, null, null, str_repeat('e', 40), 'main', mergeCommitSha: str_repeat('5', 40), mergedAt: '2026-11-19T10:00:00Z');
        });
    }));
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'prod-node', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-prod-key']));
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/hardimpactdev/app.git', 'default_branch' => 'main', 'root' => 'public']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production',
        'checkout_path' => '/home/orbit/apps/production', 'status' => 'active', 'source_layout' => 'checkout', 'root' => 'public']);
}

function deployRow(Instance $instance, string $commit, string $started, string $finished, string $status = 'succeeded', string $kind = 'deploy'): InstanceDeployment
{
    return InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => $kind, 'commit' => $commit, 'status' => $status,
        'started_at' => $started, 'finished_at' => $finished, 'triggered_by' => 'manual']);
}

function mergedGroup(Instance $instance, string $title, ?string $sha, string $mergedAt, ?string $prUrl = null): Task
{
    $group = Task::topLevel()->create(['project_id' => $instance->project_id, 'title' => $title, 'brief' => 'Brief', 'status' => TaskGroupStatus::Completed,
        'ended_at' => $mergedAt, 'merged_at' => $mergedAt, 'pr_url' => $prUrl]);
    DB::table('tasks')->where('id', $group->id)->update(['merged_sha' => $sha]);

    return $group;
}

it('stamps the merged groups whose merge commit a production deploy carried, and only those', function (): void {
    $sha = static fn (string $c): string => str_repeat($c, 40);
    $reads = [];
    // main: 1 (deployed before), 2, 3 (deployed now), 4 (merged after the commit was cut); 9 is on no branch.
    $instance = deployWorld([$sha('1'), $sha('2'), $sha('5'), $sha('3'), $sha('4')], $reads);
    $previous = deployRow($instance, $sha('1'), '2026-11-19 08:00:00', '2026-11-19 08:05:00');
    $old = mergedGroup($instance, 'Merged before the previous deploy started', $sha('1'), '2026-11-19 07:00:00');
    $carriedBefore = mergedGroup($instance, 'Merged while the previous deploy ran and carried by it', $sha('1'), '2026-11-19 08:01:00');
    $carried = mergedGroup($instance, 'Carried', $sha('2'), '2026-11-19 09:00:00');
    $byPullRequest = mergedGroup($instance, 'Merge commit read from its pull request', null, '2026-11-19 10:00:00', 'https://github.com/hardimpactdev/app/pull/41');
    $tip = mergedGroup($instance, 'The deployed commit itself', $sha('3'), '2026-11-19 10:30:00');
    $later = mergedGroup($instance, 'Merged after the commit was cut', $sha('4'), '2026-11-19 10:50:00');
    $elsewhere = mergedGroup($instance, 'Merge commit not on the deployed branch', $sha('9'), '2026-11-19 09:30:00');
    $stamped = mergedGroup($instance, 'Already stamped', $sha('2'), '2026-11-19 09:10:00');
    $stamped->update(['deployed_at' => '2026-11-19 09:20:00']);
    $deployment = deployRow($instance, $sha('3'), '2026-11-19 10:40:00', '2026-11-19 11:00:00');

    app()->call([new StampDeployedTasks($deployment->id), 'handle']);
    app()->call([new StampDeployedTasks($deployment->id), 'handle']);

    $at = static fn (Task $group): ?string => $group->refresh()->deployed_at?->toDateTimeString();
    expect($at($carried))->toBe('2026-11-19 11:00:00')
        ->and($at($byPullRequest))->toBe('2026-11-19 11:00:00')
        ->and($at($tip))->toBe('2026-11-19 11:00:00')
        ->and($at($carriedBefore))->toBe('2026-11-19 08:05:00')
        ->and($at($stamped))->toBe('2026-11-19 09:20:00')
        ->and($at($old))->toBeNull()
        ->and($at($later))->toBeNull()
        ->and($at($elsewhere))->toBeNull()
        ->and($previous->refresh()->status)->toBe('succeeded')
        ->and($reads)->toContain('pull:41');
});

it('stamps nothing after a first deploy, a rollback, a failed deploy, or a failed GitHub read', function (string $case): void {
    $sha = static fn (string $c): string => str_repeat($c, 40);
    $reads = [];
    $instance = deployWorld([$sha('1'), $sha('2'), $sha('3')], $reads, unreadable: $case === 'unreadable');
    if ($case !== 'first') {
        deployRow($instance, $sha('1'), '2026-11-19 08:00:00', '2026-11-19 08:05:00');
    }
    $group = mergedGroup($instance, 'Merged', $sha('2'), '2026-11-19 09:00:00');
    $deployment = deployRow($instance, $sha('3'), '2026-11-19 10:40:00', '2026-11-19 11:00:00',
        status: $case === 'failed' ? 'failed' : 'succeeded', kind: $case === 'rollback' ? InstanceDeployment::Rollback : 'deploy');

    app()->call([new StampDeployedTasks($deployment->id), 'handle']);

    expect($group->refresh()->deployed_at)->toBeNull()
        ->and($reads)->toBe($case === 'unreadable' ? ['compare:2...3'] : []);
})->with(['first', 'rollback', 'failed', 'unreadable']);

it('queues the stamp after a succeeded production deploy is recorded, and not after a failed one', function (string $status): void {
    Queue::fake();
    $instance = deployWorld([]);
    $recorder = app(InstanceDeploymentRecorder::class);
    $row = $recorder->start($instance, 'manual');

    DB::transaction(fn () => $recorder->recordNative($row, $status, null, ['release' => 'r1', 'selected_release' => 'r1', 'commit' => str_repeat('3', 40), 'failed_step' => null]));

    expect($row->refresh()->status)->toBe($status);
    $status === 'succeeded'
        ? Queue::assertPushed(StampDeployedTasks::class, fn (StampDeployedTasks $job): bool => $job->deploymentId === $row->id)
        : Queue::assertNotPushed(StampDeployedTasks::class);
})->with(['succeeded', 'failed']);

it('reports delivery time from ready to deploy and the deploy wait from merge to deploy, null while nothing deployed', function (): void {
    $instance = deployWorld([]);
    $group = static function (string $ready, string $merged, ?string $deployed) use ($instance): Task {
        $task = Task::topLevel()->create(['project_id' => $instance->project_id, 'title' => 'T', 'brief' => 'Brief', 'status' => TaskGroupStatus::Completed,
            'ended_at' => $merged, 'merged_at' => $merged, 'deployed_at' => $deployed]);
        DB::table('tasks')->where('id', $task->id)->update(['ready_at' => $ready]);
        AgentThread::query()->create(['task_group_id' => $task->id, 'driver' => 't3', 'runtime_key' => 'x', 'external_id' => (string) Str::uuid(), 'role' => 'implementer', 'tokens' => 10]);

        return $task;
    };
    $report = static fn () => app(TaskInsights::class)->report(7, $instance->project_id, CarbonImmutable::now());
    $group('2026-11-18 08:00:00', '2026-11-18 09:00:00', null);
    expect($report()->current->deliveryTimeMs)->toBeNull()->and($report()->current->deployWaitMs)->toBeNull();

    $deployed = $group('2026-11-18 10:00:00', '2026-11-18 12:00:00', '2026-11-18 12:30:00');
    $group('2026-11-18 10:00:00', '2026-11-18 11:00:00', '2026-11-18 14:00:00');
    $data = $report()->current->toArray();

    expect($data['delivery_time_ms'])->toBe(9_000_000)->and($data['deploy_wait_ms'])->toBe(1_800_000)
        ->and($data['lead_time_ms'])->toBe(3_600_000);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $gateway = Node::withoutEvents(fn () => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->getJson('/api/v1/tasks/'.$deployed->id)->assertOk()->assertJsonPath('data.deployed_at', '2026-11-18T12:30:00+00:00');
    $this->getJson('/api/v1/task-insights?period=7d')->assertOk()->assertJsonPath('data.current.delivery_time_ms', 9_000_000)
        ->assertJsonPath('data.previous.delivery_time_ms', null)->assertJsonPath('data.previous.deploy_wait_ms', null);
});

it('reads only the columns a report computes from, so twelve weeks of tasks fit the web runtime memory limit', function (): void {
    $instance = deployWorld([]);
    Task::topLevel()->create(['project_id' => $instance->project_id, 'title' => 'T', 'brief' => str_repeat('b', 4000), 'status' => TaskGroupStatus::Completed,
        'ended_at' => now(), 'merged_at' => now()])->tasks()->create(['title' => 'S', 'brief' => str_repeat('s', 4000), 'position' => 1]);
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    app(TaskInsights::class)->report(30, null, CarbonImmutable::now());

    // A whole task or thread row carries briefs, summaries and errors; twelve weeks of them exhausted the 128M limit (gateway.unhandled).
    expect(array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '*') && ! str_contains($sql, 'count(*)'))))->toBe([]);
});
