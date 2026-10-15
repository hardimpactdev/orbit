<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\UpdateInstanceAction;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Feature\GitHub\GreenCommitFixtures;
use Tests\Support\FakeRemoteBranchHeads;
use Tests\Support\Orb220DeploymentApiFixture;

/**
 * A first-parent history, oldest first, and the state of the required check on each commit: `success`, `failure`,
 * `queued`, or `none` for no run. The branch head is the last commit.
 *
 * @param  array<string, string>  $checks
 */
function auto_deploy_github(object $test, array $checks): void
{
    $test->checks = $checks;
    $test->heads->set($test->instance->project, 'main', array_last(array_keys($checks)));

    if ($test->githubFaked ?? false) {
        return;
    }

    $test->githubFaked = true;
    Http::fake(static function (Request $request) use ($test) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $checks = $test->checks;
        $shas = array_keys($checks);
        $commits = [];
        foreach ($shas as $index => $sha) {
            $commits[] = ['sha' => $sha, 'parents' => $index === 0 ? [] : [['sha' => $shas[$index - 1]]]];
        }

        return match (true) {
            $path === '/repos/acme/shop/installation' => Http::response(['id' => 9]),
            $path === '/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_'.array_key_first($request->data()['permissions'])], 201),
            $path === '/repos/acme/shop/commits' => Http::response(array_reverse($commits)),
            preg_match('#\A/repos/acme/shop/commits/([0-9a-f]{40})/check-runs\z#', $path, $match) === 1 => Http::response(GreenCommitFixtures::requiredChecksAs($checks[$match[1]], $match[1])),
            preg_match('#\A/repos/acme/shop/compare/([0-9a-f]{40})\.\.\.[0-9a-f]{40}\z#', $path, $match) === 1 => Http::response(['status' => 'ahead', 'ahead_by' => 1, 'behind_by' => 0, 'total_commits' => 1, 'base_commit' => ['sha' => $match[1]], 'merge_base_commit' => ['sha' => $match[1]], 'commits' => [], 'files' => []]),
        };
    });
}

function auto_deploy_check_reads(): int
{
    return Http::recorded(static fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/check-runs'))->count();
}

/** @param array<string, mixed> $attributes */
function auto_deploy_row(Instance $instance, array $attributes): InstanceDeployment
{
    return InstanceDeployment::query()->create([
        'instance_id' => $instance->id,
        'branch' => 'main',
        'started_at' => now(),
        'finished_at' => now(),
        'status' => 'succeeded',
        'triggered_by' => 'laptop',
        'kind' => 'deploy',
        ...$attributes,
    ]);
}

function auto_deploy_tick(object $test): void
{
    $test->artisan('orbit:auto-deploy')->run();
}

beforeEach(function (): void {
    enable_operation_queue();
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->instance = $this->fixture->instance;
    $this->instance->project->update(['repository_url' => 'https://github.com/acme/shop.git', 'merge_check' => 'Required checks']);
    $this->heads = new FakeRemoteBranchHeads;
    app()->instance(RemoteBranchHeads::class, $this->heads);
    [$this->a, $this->b, $this->c, $this->d] = [str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40), str_repeat('d', 40)];
    auto_deploy_row($this->instance, ['commit' => $this->a]);
    app(UpdateInstanceAction::class)->execute($this->instance, null, true);
    $this->instance->refresh();
});

describe('automatic deployment of a production Instance', function (): void {
    it('deploys the newest green commit, pinned to exactly that commit, and then stays quiet', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success', $this->c => 'success']);

        auto_deploy_tick($this);
        $operation = Operation::query()->sole();

        expect($operation->input)->toBe(['commit' => $this->c])
            ->and($operation->triggered_by)->toBe('schedule');

        run_operation_jobs();
        $row = InstanceDeployment::query()->latest('id')->first();
        expect($row?->commit)->toBe($this->c)
            ->and($row?->status)->toBe('succeeded')
            ->and($this->fixture->deployment->preparedCommits)->toBe([$this->c]);

        $reads = count(Http::recorded());
        auto_deploy_tick($this);
        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(1)
            ->and(InstanceDeployment::query()->count())->toBe(2)
            ->and(count(Http::recorded()))->toBe($reads)
            ->and($this->instance->fresh()->auto_deploy_state['result'] ?? null)->toBe('up_to_date');
    });

    it('never deploys a pending head, and deploys it on the tick after its check passes', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'queued']);

        auto_deploy_tick($this);
        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(0)
            ->and($this->instance->fresh()->auto_deploy_state['result'] ?? null)->toBe('gate_pending');

        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success']);
        auto_deploy_tick($this);

        expect(Operation::query()->sole()->input)->toBe(['commit' => $this->b]);
    });

    it('never deploys a red head and reads no check run again until the head changes', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'failure']);

        auto_deploy_tick($this);
        $reads = auto_deploy_check_reads();
        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(0)
            ->and(auto_deploy_check_reads())->toBe($reads)
            ->and($this->instance->fresh()->auto_deploy_state['result'] ?? null)->toBe('gate_failed')
            ->and(Activity::query()->where('description', 'auto_deploy.gate_failed')->count())->toBe(1);

        auto_deploy_github($this, [$this->a => 'success', $this->b => 'failure', $this->c => 'success']);
        auto_deploy_tick($this);

        expect(Operation::query()->sole()->input)->toBe(['commit' => $this->c]);
    });

    it('deploys the newest green commit behind a red head', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success', $this->c => 'failure']);

        auto_deploy_tick($this);

        expect(Operation::query()->sole()->input)->toBe(['commit' => $this->b]);
    });

    it('treats a head without a check run as pending', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'none']);

        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(0)
            ->and($this->instance->fresh()->auto_deploy_state['result'] ?? null)->toBe('gate_pending');
    });

    it('deploys the head without a gate when the Project has no merge check', function (): void {
        $this->instance->project->update(['merge_check' => null]);
        $this->heads->set($this->instance->project, 'main', $this->b);

        auto_deploy_tick($this);

        expect(Operation::query()->sole()->input)->toBe(['commit' => $this->b]);
        Http::assertNothingSent();
    });

    it('does not retry a commit that failed in its own steps until a new commit or a manual deployment', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success']);
        auto_deploy_row($this->instance, ['commit' => $this->b, 'triggered_by' => 'schedule', 'status' => 'failed', 'failed_step' => 'before_activation', 'error_code' => 'deployment.step_failed']);
        $this->travel(30)->minutes();

        auto_deploy_tick($this);
        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(0)
            ->and($this->instance->fresh()->auto_deploy_state['result'] ?? null)->toBe('no_green_commit');

        auto_deploy_row($this->instance, ['commit' => $this->a, 'triggered_by' => 'laptop', 'status' => 'failed', 'failed_step' => 'environment', 'error_code' => 'env.sync_failed']);
        auto_deploy_tick($this);

        expect(Operation::query()->sole()->input)->toBe(['commit' => $this->b]);
    });

    it('moves on to a new commit after a failed one', function (): void {
        auto_deploy_row($this->instance, ['commit' => $this->b, 'triggered_by' => 'schedule', 'status' => 'failed', 'failed_step' => 'after_activation', 'error_code' => 'deployment.step_failed']);
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success', $this->c => 'success']);

        auto_deploy_tick($this);

        expect(Operation::query()->sole()->input)->toBe(['commit' => $this->c]);
    });

    it('keeps a failed head of an ungated Project from deploying again', function (): void {
        $this->instance->project->update(['merge_check' => null]);
        $this->heads->set($this->instance->project, 'main', $this->b);
        auto_deploy_row($this->instance, ['commit' => $this->b, 'triggered_by' => 'schedule', 'status' => 'failed', 'failed_step' => 'before_activation', 'error_code' => 'deployment.step_failed']);
        $this->travel(30)->minutes();

        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(0)
            ->and($this->instance->fresh()->auto_deploy_state['result'] ?? null)->toBe('commit_failed');
    });

    it('retries a transient failure after ten minutes, not every minute', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success']);
        $this->fixture->deployment->failPreparation = true;
        auto_deploy_tick($this);
        run_operation_jobs();

        auto_deploy_tick($this);
        expect(Operation::query()->count())->toBe(1)
            ->and($this->instance->fresh()->auto_deploy_state['result'] ?? null)->toBe('backing_off');

        $this->fixture->deployment->failPreparation = false;
        $this->travel(11)->minutes();
        auto_deploy_tick($this);
        run_operation_jobs();

        expect(InstanceDeployment::query()->where('triggered_by', 'schedule')->orderBy('id')->pluck('status')->all())->toBe(['failed', 'succeeded']);
    });

    it('pauses after a rollback, never overrides it, and resumes when auto_deploy is set again', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success', $this->c => 'success']);
        auto_deploy_row($this->instance, ['commit' => $this->c, 'triggered_by' => 'schedule']);
        $rollback = auto_deploy_row($this->instance, ['commit' => $this->a, 'kind' => 'rollback', 'release' => 'initial']);

        auto_deploy_tick($this);
        auto_deploy_tick($this);

        $state = $this->instance->fresh()->auto_deploy_state;
        expect(Operation::query()->count())->toBe(0)
            ->and($state['result'] ?? null)->toBe('paused')
            ->and($state['pause']['deployment_id'] ?? null)->toBe($rollback->id)
            ->and(Activity::query()->where('description', 'auto_deploy.paused')->count())->toBe(1);

        $show = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])->getJson("/api/v1/instances/{$this->instance->id}");
        $show()->assertOk()
            ->assertJsonPath('data.auto_deploy.enabled', true)
            ->assertJsonPath('data.auto_deploy.gate', 'Required checks')
            ->assertJsonPath('data.auto_deploy.result', 'paused')
            ->assertJsonPath('data.auto_deploy.pause.reason', 'rollback')
            ->assertJsonPath('data.auto_deploy.pause.deployment_id', $rollback->id);

        app(UpdateInstanceAction::class)->execute($this->instance, null, true);
        $show()->assertJsonPath('data.auto_deploy.pause', null);
        auto_deploy_tick($this);

        expect(Operation::query()->sole()->input)->toBe(['commit' => $this->c]);
    });

    it('continues from a manual deployment of the branch head', function (): void {
        auto_deploy_row($this->instance, ['commit' => $this->b, 'triggered_by' => 'laptop']);
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success', $this->c => 'success']);

        auto_deploy_tick($this);

        expect(Operation::query()->sole()->input)->toBe(['commit' => $this->c]);
    });

    it('skips an Instance that another operation holds and records the skip once', function (): void {
        auto_deploy_github($this, [$this->a => 'success', $this->b => 'success']);
        $manual = app(StartInstanceDeployAction::class)->execute($this->instance, 'laptop', null);

        auto_deploy_tick($this);
        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(1)
            ->and($this->instance->fresh()->auto_deploy_state['operation_id'] ?? null)->toBe($manual->id)
            ->and(Activity::query()->where('description', 'auto_deploy.busy')->count())->toBe(1);
    });

    it('waits for a manual first deployment', function (): void {
        InstanceDeployment::query()->delete();

        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(0)
            ->and($this->heads->reads)->toBe([])
            ->and($this->instance->fresh()->auto_deploy_state['result'] ?? null)->toBe('not_deployed');
    });

    it('fails the tick when GitHub cannot answer the gate, without deploying', function (): void {
        $this->heads->set($this->instance->project, 'main', $this->b);
        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('instance.auto_deploy_gate_unavailable')->assertFailed();

        expect(Operation::query()->count())->toBe(0);
    });

    it('fails the tick for a merge check that the GitHub App cannot read', function (): void {
        $this->instance->project->update(['source_access' => 'gh_cli']);
        $this->heads->set($this->instance->project, 'main', $this->b);

        $this->artisan('orbit:auto-deploy')->expectsOutputToContain('instance.auto_deploy_gate_unreadable')->assertFailed();

        expect(Operation::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('leaves Instances without auto_deploy alone', function (): void {
        app(UpdateInstanceAction::class)->execute($this->instance, null, false);
        $this->heads->set($this->instance->project, 'main', $this->b);

        auto_deploy_tick($this);

        expect(Operation::query()->count())->toBe(0)
            ->and($this->heads->reads)->toBe([]);
    });
});
