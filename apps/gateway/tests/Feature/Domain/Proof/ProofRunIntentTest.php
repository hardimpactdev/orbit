<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofCommitRange;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofCommitTrust;
use App\Domain\Proof\ProofPullRequest;
use App\Domain\Proof\ProofRunIntent;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskVmAdmission;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Jobs\Proof\AdmitProofRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Jobs\Tasks\AdmitTaskVm;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const PROOF_SHA = 'b3f1c0de00000000000000000000000000000001';

/** A repository that GitHub would describe: configure what the commit is, what it changes and who approved it. */
function proofSource(): object
{
    return new class implements ProofCommitSource
    {
        public bool $exists = true;

        /** @var list<string> */
        public array $branches = ['feature'];

        /** @var list<ProofPullRequest> */
        public array $pullRequests = [];

        /** @var list<string> */
        public array $writers = ['maintainer'];

        /** @var list<GitHubReview> */
        public array $reviews = [];

        /** @var list<ProofChangedFile> */
        public array $files = [];

        public bool $complete = true;

        public ?string $list = "docs/**  skip\nswamp/workflows/workflow-firewall-*.yaml  firewall:allow\nswamp/proof/firewall/*.cases.yaml  firewall:allow\nswamp/workflows/workflow-vault-sync.yaml  none: no case yet\n";

        /** @var list<string> */
        public array $paths = ['swamp/proof/firewall/allow.cases.yaml'];

        public bool $down = false;

        public int $reads = 0;

        public function commitExists(string $sha): bool
        {
            $this->reads++;

            return $this->down ? throw GitHubApiException::unavailable() : $this->exists;
        }

        public function branchesWithHead(string $sha): array
        {
            return $this->branches;
        }

        public function pullRequestsWithHead(string $sha): array
        {
            return $this->pullRequests;
        }

        public function canWrite(string $login): bool
        {
            return in_array($login, $this->writers, true);
        }

        public function reviews(int $pullRequest): array
        {
            return $this->reviews;
        }

        public function changes(string $sha): ProofChangedFiles
        {
            return new ProofChangedFiles(str_repeat('a', 40), $this->files, $this->complete);
        }

        public function file(string $sha, string $path): ?string
        {
            return $this->list;
        }

        public function proofPaths(string $sha): array
        {
            return $this->paths;
        }

        /** @var array<string,ProofCommitRange|null> release commit => how the merge base stands to it; an unknown commit is null */
        public array $ranges = [];

        public function range(string $from, string $to): ?ProofCommitRange
        {
            return $this->ranges[$from] ?? null;
        }
    };
}

function proofReview(int $id, string $login, GitHubReviewState $state, string $commit = PROOF_SHA): GitHubReview
{
    return new GitHubReview($id, $id, $login, $state, $commit, new DateTimeImmutable('2026-11-05T10:00:00Z + '.$id.' minutes'), 'https://example.test', '');
}

function proofRefused(callable $callback): ResourceOperationException
{
    try {
        $callback();
    } catch (ResourceOperationException $exception) {
        return $exception;
    }
    throw new LogicException('Expected a refusal.');
}

function proofWorld(object $source): void
{
    app()->instance(ProofCommitSource::class, $source);
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
}

describe('which commits Orbit proves', function (): void {
    it('accepts the head of a branch of the repository, and the head of a merge queue branch', function (): void {
        $source = proofSource();
        $source->branches = ['gh-readonly-queue/main/pr-1-abc'];

        expect(fn () => app(ProofCommitTrust::class, ['source' => $source])->assert(PROOF_SHA))->not->toThrow(ResourceOperationException::class);
    });

    it('refuses a commit that is not a head and has no pull request', function (): void {
        $source = proofSource();
        $source->branches = [];

        expect(proofRefused(fn () => app(ProofCommitTrust::class, ['source' => $source])->assert(PROOF_SHA))->errorCode)->toBe('proof.commit_untrusted');
    });

    it('refuses an unknown commit', function (): void {
        $source = proofSource();
        $source->exists = false;

        expect(proofRefused(fn () => app(ProofCommitTrust::class, ['source' => $source])->assert(PROOF_SHA))->errorCode)->toBe('proof.commit_not_found');
    });

    it('refuses a commit when GitHub cannot be read, and never as untrusted', function (): void {
        $source = proofSource();
        $source->down = true;

        expect(proofRefused(fn () => app(ProofCommitTrust::class, ['source' => $source])->assert(PROOF_SHA))->errorCode)->toBe('proof.github_unavailable');
    });

    it('checks a pull request from outside: the label, a maintainer and exactly the head', function (
        array $labels, array $reviews, bool $open, string $head, bool $accepted,
    ): void {
        $source = proofSource();
        $source->branches = [];
        $source->pullRequests = [new ProofPullRequest(7, $open, $head, $labels)];
        $source->reviews = array_map(static fn (Closure $make): GitHubReview => $make(), $reviews);
        $trust = app(ProofCommitTrust::class, ['source' => $source]);

        if ($accepted) {
            $trust->assert(PROOF_SHA);
            expect(true)->toBeTrue();
        } else {
            expect(proofRefused(fn () => $trust->assert(PROOF_SHA))->errorCode)->toBe('proof.commit_untrusted');
        }
    })->with([
        'unapproved, no label' => [[], [], true, PROOF_SHA, false],
        'label without approval' => [['run-proofs'], [], true, PROOF_SHA, false],
        'approval without label' => [[], [fn () => proofReview(1, 'maintainer', GitHubReviewState::Approved)], true, PROOF_SHA, false],
        'approval by a non-maintainer' => [['run-proofs'], [fn () => proofReview(1, 'stranger', GitHubReviewState::Approved)], true, PROOF_SHA, false],
        'approved with the label' => [['run-proofs'], [fn () => proofReview(1, 'maintainer', GitHubReviewState::Approved)], true, PROOF_SHA, true],
        'approval of an older commit' => [['run-proofs'], [fn () => proofReview(1, 'maintainer', GitHubReviewState::Approved, str_repeat('0', 40))], true, PROOF_SHA, false],
        'a later change request' => [['run-proofs'], [fn () => proofReview(1, 'maintainer', GitHubReviewState::Approved), fn () => proofReview(2, 'maintainer', GitHubReviewState::ChangesRequested)], true, PROOF_SHA, false],
        'a later comment keeps the approval' => [['run-proofs'], [fn () => proofReview(1, 'maintainer', GitHubReviewState::Approved), fn () => proofReview(2, 'maintainer', GitHubReviewState::Commented)], true, PROOF_SHA, true],
        'a dismissed approval' => [['run-proofs'], [fn () => proofReview(1, 'maintainer', GitHubReviewState::Approved), fn () => proofReview(2, 'maintainer', GitHubReviewState::Dismissed)], true, PROOF_SHA, false],
        'the head moved on (stale approval)' => [['run-proofs'], [fn () => proofReview(1, 'maintainer', GitHubReviewState::Approved)], true, str_repeat('1', 40), false],
        'a closed pull request' => [['run-proofs'], [fn () => proofReview(1, 'maintainer', GitHubReviewState::Approved)], false, PROOF_SHA, false],
    ]);
});

/** The request, then the queue job: the proof as the admission left it. */
function proofAdmitted(bool $all = false, bool $reportOnly = false, ?Node $caller = null): Operation
{
    Queue::fake();
    $proof = app(ProofRunIntent::class)->start(PROOF_SHA, $all, 'tester', null, reportOnly: $reportOnly, authenticatedCaller: $caller);
    app(ProofRunIntent::class)->admit($proof->id);

    return $proof->refresh();
}

/** The message of the failure, as the Operation shows it. */
function proofFailureDetail(Operation $proof): ?string
{
    return $proof->state['native_failure']['detail'] ?? null;
}

/**
 * One host with room for one large task VM and a base snapshot that a refresh built from the release `$copiesCommit`, and a commit under proof that
 * changes a firewall workflow. Returns the temporary release layout, which the test removes.
 */
function proofHostWithCopies(object $source, ?string $copiesCommit, int $taskPlaces = 6): string
{
    $base = sys_get_temp_dir().'/orbit-proof-copies-'.bin2hex(random_bytes(4));
    $commit = 'eeeeeeeeeeee0000000000000000000000000000';
    $release = $base.'/releases/'.substr($commit, 0, 12);
    mkdir($release.'/apps/gateway/resources/machine-software', 0700, true);
    file_put_contents($release.'/REVISION', $commit."\n");
    symlink('releases/'.substr($commit, 0, 12), $base.'/orbit');
    copy(base_path('resources/machine-software/recipe.json'), $release.'/apps/gateway/resources/machine-software/recipe.json');
    config(['orbit.gateway_checkout' => $base.'/orbit/apps/gateway', 'proof.execution' => true]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['proof.project' => $project->id, 'swamp.proof_club_api_key' => 'club-key']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']));
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now(),
        'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => 'beast-base', 'seed' => 'beast-base-seed', 'snapshot' => 'r20261107120000']]]);
    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id, 'status' => OperationStatus::Succeeded,
        'input' => $copiesCommit === null ? [] : ['commit' => $copiesCommit],
        'result' => ['refreshed' => true, 'host_node_id' => $host->id, 'build_id' => $build->id], 'triggered_by' => 'test']);
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25', [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', $taskPlaces, 4, '8GiB', '20GiB', ciPlaces: 2)], '/pi', str_repeat('a', 64), ['gpt-6'], largeProjectIds: [$project->id]));
    $source->files = [new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')];
    proofWorld($source);

    return $base;
}
describe('proof:run admission', function (): void {
    it('answers after one insert: the proof is recorded and the admission is queued, with no read of GitHub and no run', function (): void {
        Queue::fake();
        $source = proofSource();
        $source->exists = false;
        proofWorld($source);

        $started = microtime(true);
        $proof = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);
        $seconds = microtime(true) - $started;

        expect($proof->status)->toBe(OperationStatus::Running)
            ->and($proof->input)->toBe(['commit' => PROOF_SHA, 'all' => false, 'report_only' => false, 'caller_node_id' => null, 'ci_pool' => false, 'runs' => []])
            ->and($seconds)->toBeLessThan(1.0)
            ->and(Task::query()->count())->toBe(0)->and(Operation::query()->where('kind', 'proof.command')->count())->toBe(0);
        Queue::assertPushed(AdmitProofRun::class, static fn (AdmitProofRun $job): bool => $job->operationId === $proof->id);
    });

    it('refuses the request itself only for what the database shows: no Swamp Node', function (): void {
        Queue::fake();

        expect(proofRefused(fn () => app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null))->errorCode)->toBe('proof.swamp_unavailable');
        Queue::assertNothingPushed();
    });

    it('does nothing for a proof that is no longer running or already has its runs', function (): void {
        Queue::fake();
        proofWorld(proofSource());
        $proof = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);
        $proof->update(['status' => OperationStatus::Cancelled]);

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Cancelled)->and($proof->input)->not->toHaveKey('cases');
    });

    it('ends the proof with a code when the queue refuses the job, instead of leaving it for the watchdog', function (): void {
        proofWorld(proofSource());
        app()->instance('queue', new class
        {
            /** @param  array<int, mixed>  $arguments */
            public function __call(string $method, array $arguments): never
            {
                throw new RuntimeException('The queue is down.');
            }
        });

        $proof = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.queue_unavailable')
            ->and(proofFailureDetail($proof))->toContain('could not queue');
    });

    it('leaves a proof that was cancelled while the job read GitHub cancelled', function (): void {
        proofWorld(proofSource());
        Queue::fake();
        $proof = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);
        $armed = true;
        // The proof is cancelled right after the job records the selection, before it admits anything.
        Operation::updated(static function (Operation $operation) use (&$armed): void {
            if ($armed && $operation->kind === 'proof.run' && isset($operation->input['cases'])) {
                $armed = false;
                Operation::query()->whereKey($operation->id)->update(['status' => OperationStatus::Cancelled->value]);
            }
        });

        app(ProofRunIntent::class)->admit($proof->id);

        $armed = false;
        expect($proof->refresh()->status)->toBe(OperationStatus::Cancelled)->and($proof->error_code)->toBeNull();
    });

    it('returns the same proof for a repeated key and a proof that already has its runs is not admitted again', function (): void {
        Queue::fake();
        proofWorld(proofSource());
        $proof = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);
        $proof->update(['input' => [...$proof->input, 'runs' => [['command' => 'a:b', 'file' => 'f', 'group_id' => 1, 'operation_id' => 2]]]]);

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->input)->not->toHaveKey('cases')->and($proof->status)->toBe(OperationStatus::Running);
        Queue::assertPushed(AdmitProofRun::class, 1);
    });

    it('queues the admission on the tasks queue of the Gateway connection and hands the proof to the intent', function (): void {
        $job = new AdmitProofRun(7);

        expect($job->connection)->toBe('gateway')->and($job->queue)->toBe('tasks')->and($job->tries)->toBe(1)->and($job->timeout)->toBe(1200)
            ->and($job->operationId)->toBe(7);
    });

    it('succeeds with nothing to prove when only documentation changed, and lists the uncovered paths', function (): void {
        $source = proofSource();
        $source->files = [new ProofChangedFile('docs/a.md', 'modified'), new ProofChangedFile('swamp/workflows/workflow-vault-sync.yaml', 'modified')];
        proofWorld($source);

        $operation = proofAdmitted();

        expect($operation->kind)->toBe('proof.run')->and($operation->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->result)->toBe(['commit' => PROOF_SHA, 'outcome' => 'nothing_to_prove', 'reason' => 'proof.nothing_to_prove', 'cases' => [],
                'uncovered' => [['path' => 'swamp/workflows/workflow-vault-sync.yaml', 'reason' => 'none: no case yet']], 'receipt_url' => null]);
    });

    it('fails the proof for an unmapped path, with the code and the message on the Operation', function (): void {
        $source = proofSource();
        $source->files = [new ProofChangedFile('unknown/new.file', 'added')];
        proofWorld($source);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.path_unmapped')
            ->and(proofFailureDetail($proof))->not->toBeNull()->and($proof->result['outcome'])->toBe('failed');
    });

    it('fails the proof for an untrusted commit before it reads the diff', function (): void {
        $source = proofSource();
        $source->branches = [];
        proofWorld($source);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.commit_untrusted')
            ->and($proof->input)->not->toHaveKey('cases');
    });

    it('is on by default, as in production', function (): void {
        expect(config('proof.execution'))->toBeTrue();
    });

    it('keeps execution on unless the variable says it is off', function (?string $value, bool $expected): void {
        $environment = Env::getRepository();

        try {
            $value === null ? $environment->clear('ORBIT_PROOF_RUN_EXECUTION') : $environment->set('ORBIT_PROOF_RUN_EXECUTION', $value);

            expect((require base_path('config/proof.php'))['execution'])->toBe($expected);
        } finally {
            $environment->clear('ORBIT_PROOF_RUN_EXECUTION');
        }
    })->with([[null, true], ['true', true], ['1', true], ['false', false], ['0', false], ['off', false], ['no', false], ['', false]]);

    it('selects the cases and fails while execution is switched off', function (): void {
        config(['proof.execution' => false]);
        $source = proofSource();
        $source->files = [new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')];
        proofWorld($source);

        $proof = proofAdmitted();

        expect(config('proof.execution'))->toBeFalse()
            ->and($proof->error_code)->toBe('proof.execution_disabled')
            ->and(array_column($proof->input['cases'], 'file'))->toBe(['swamp/proof/firewall/allow.cases.yaml']);
    });

    it('runs every case for --all and never lets a docs-only commit skip it', function (): void {
        config(['proof.execution' => false]);
        $source = proofSource();
        $source->files = [new ProofChangedFile('docs/a.md', 'modified')];
        proofWorld($source);

        $proof = proofAdmitted(all: true);

        expect($proof->error_code)->toBe('proof.execution_disabled')->and($proof->input['cases'])->toHaveCount(1);
    });

    it('queues an operator proof in task admission without a VM, then times out clearly', function (bool $reportOnly): void {
        proofWorldWithoutCapacity();
        $proof = proofAdmitted(reportOnly: $reportOnly);
        expect($proof->error_code)->toBeNull()->and($proof->status)->toBe(OperationStatus::Running)
            ->and(TaskVm::query()->count())->toBe(0);
        $child = ProofRunSettlement::children($proof->id)->sole();
        expect($child->status)->toBe(OperationStatus::Waiting)->and($child->current_step)->toBe('Wait for a task place');
        Queue::assertPushed(AdmitTaskVm::class);
        $child->forceFill(['created_at' => now()->subSeconds(ProofRunIntent::WaitSeconds + 1)])->save();
        app(TaskVmAdmission::class)->admit($child->id);
        expect($child->refresh()->error_code)->toBe('tasks.capacity_timeout')
            ->and($proof->refresh()->status)->toBe(OperationStatus::Failed);
    })->with([true, false]);

    it('places the proof on a host only when its ready copies include every change of what the image carries up to the merge base', function (?string $copies, ?array $range, bool $skipped): void {
        $source = proofSource();
        $base = proofHostWithCopies($source, $copies);
        if ($copies !== null) {
            $source->ranges[$copies] = $range === null ? null : new ProofCommitRange(GitHubComparisonStatus::from($range[0]), new ProofChangedFiles(str_repeat('f', 40),
                array_map(static fn (string $path): ProofChangedFile => new ProofChangedFile($path, 'modified'), $range[1]), $range[2] ?? true));
        }

        $proof = proofAdmitted();

        Process::run(['rm', '-rf', $base]);
        $child = ProofRunSettlement::children($proof->id)->first();
        $detail = $child?->state['capacity_wait_reason'] ?? $proof->state['capacity_wait_reason'] ?? proofFailureDetail($proof) ?? '';
        expect(str_contains($detail, 'no usable nested fleet seed are skipped: beast'))->toBe($skipped)
            ->and(str_contains($detail, 'playbooks, pins and lock files up to the merge base'))->toBe($skipped);
        if ($skipped) {
            expect(ProofRunSettlement::children($proof->id)->sole()->current_step)->toBe('Wait for a task place');
        }
    })->with([
        'main moved by documents and Gateway PHP only' => ['dddddddddddd0000000000000000000000000000', ['ahead', ['docs/a.md', 'apps/gateway/app/Domain/Proof/ProofRunIntent.php']], false],
        'the copies are the merge base' => ['dddddddddddd0000000000000000000000000000', ['identical', []], false],
        'the copies are after the merge base' => ['dddddddddddd0000000000000000000000000000', ['behind', []], false],
        'main moved by a playbook' => ['aaaaaaaaaaaa0000000000000000000000000000', ['ahead', ['docs/a.md', 'swamp/ansible/task-vm-role.yml']], true],
        'main moved by a pin' => ['aaaaaaaaaaaa0000000000000000000000000000', ['ahead', ['apps/gateway/resources/machine-software/recipe.json']], true],
        'main moved by a lock file' => ['aaaaaaaaaaaa0000000000000000000000000000', ['ahead', ['apps/gateway/composer.lock']], true],
        'a list of files that GitHub cut' => ['aaaaaaaaaaaa0000000000000000000000000000', ['ahead', ['docs/a.md'], false], true],
        'a release GitHub does not know' => ['bbbbbbbbbbbb0000000000000000000000000000', null, true],
        'no recorded provenance' => [null, null, true],
    ]);

    it('does not release a kept VM when no host has fresh enough copies, because a freed slot would not help', function (): void {
        $source = proofSource();
        $commit = 'aaaaaaaaaaaa0000000000000000000000000000';
        $base = proofHostWithCopies($source, $commit);
        $source->ranges[$commit] = new ProofCommitRange(GitHubComparisonStatus::Ahead, new ProofChangedFiles(str_repeat('f', 40), [new ProofChangedFile('swamp/ansible/task-vm-role.yml', 'modified')], true));
        [$kept] = proofKeptRun();

        $proof = proofAdmitted();

        Process::run(['rm', '-rf', $base]);
        expect($proof->status)->toBe(OperationStatus::Running)->and(ProofRunSettlement::children($proof->id)->sole()->current_step)->toBe('Wait for a task place')
            ->and($kept->refresh()->result['kept']['cancel_requested_at'])->toBeNull();
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('fails the proof, and places nothing, when GitHub cannot say how old the copies are', function (): void {
        $source = proofSource();
        $base = proofHostWithCopies($source, 'dddddddddddd0000000000000000000000000000');
        app()->instance(ProofCommitSource::class, Mockery::mock($source::class, function ($mock) use ($source): void {
            foreach (['commitExists', 'branchesWithHead', 'pullRequestsWithHead', 'canWrite', 'reviews', 'changes', 'file', 'proofPaths'] as $method) {
                $mock->shouldReceive($method)->andReturnUsing(static fn (...$arguments) => $source->{$method}(...$arguments));
            }
            $mock->shouldReceive('range')->andThrow(GitHubApiException::unavailable());
        }));

        $proof = proofAdmitted();

        Process::run(['rm', '-rf', $base]);
        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.github_unavailable')->and(Task::query()->count())->toBe(0);
    });
    it('admits nothing when the watchdog ended the proof while the job was slow', function (): void {
        config(['proof.execution' => true]);
        app(TaskExtensionState::class)->enable();
        $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $project->id, 'swamp.proof_club_api_key' => 'club-key']);
        $source = proofSource();
        $source->files = [new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')];
        proofWorld($source);
        // The proof is ended behind the job's back once the cases are recorded, as the watchdog does for a lost job.
        $armed = true;
        Operation::updated(static function (Operation $operation) use (&$armed): void {
            if ($armed && $operation->kind === 'proof.run' && isset($operation->input['cases'])) {
                $armed = false;
                Operation::query()->whereKey($operation->id)->update(['status' => OperationStatus::Failed->value]);
            }
        });

        $proof = proofAdmitted();

        $armed = false;
        expect($proof->status)->toBe(OperationStatus::Failed)->and(Task::query()->count())->toBe(0)
            ->and(Operation::query()->where('kind', 'proof.command')->count())->toBe(0);
    });
});

/**
 * The input of one run, as ProofRunIntent builds it for a proof that was recorded with `$proofInput`.
 *
 * @param  array<string,mixed>  $proofInput
 * @return array<string,mixed>
 */
function proofRunInput(array $proofInput): array
{
    SwampNodeFixture::ensure();
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-710', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.160', 'wireguard_ip' => '10.44.0.160', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Proof', 'brief' => 'Brief', 'status' => 'reserved']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id,
        'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $guest->id, 'node_id' => $guest->id, 'status' => OperationStatus::Running, 'input' => $proofInput]);
    $method = new ReflectionMethod(ProofRunIntent::class, 'runInput');

    return $method->invoke(app(ProofRunIntent::class), $proof, PROOF_SHA, str_repeat('c', 40), ['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml'],
        ['node_id' => $guest->id, 'execution_secrets' => []], $group, $instance);
}

/** A host that cannot place the proof: with no VM settings every command is refused for capacity. */
function proofWorldWithoutCapacity(): void
{
    config(['proof.execution' => true]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['proof.project' => $project->id, 'swamp.proof_club_api_key' => 'club-key']);
    $source = proofSource();
    $source->files = [new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')];
    proofWorld($source);
}

/**
 * A failed run of an earlier proof that kept its VM (`--keep-failed`): its group is still running.
 *
 * @return array{0: Operation, 1: Task}
 */
function proofKeptRun(string $command = 'firewall:allow'): array
{
    $node = Node::query()->firstOrFail();
    $earlier = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id, 'status' => OperationStatus::Failed,
        'input' => ['commit' => str_repeat('b', 40), 'runs' => []], 'state' => [], 'sequence' => 1, 'attempts' => 0]);
    $group = Task::query()->create(['title' => 'Proof '.$command, 'brief' => 'Prove it', 'status' => TaskGroupStatus::Running]);
    $run = Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id, 'status' => OperationStatus::Failed,
        'error_code' => 'proof.cases_failed', 'input' => ['proof_operation_id' => $earlier->id, 'group_id' => $group->id, 'keep_failed' => true], 'state' => [], 'sequence' => 1, 'attempts' => 0,
        'result' => ['kept' => ['vm' => 'tvm-849', 'group_id' => $group->id, 'keep_until' => now()->addMinutes(30)->utc()->format('Y-m-d\TH:i:s\Z'), 'cancel_requested_at' => null]]]);

    return [$run, $group];
}

describe('the intent of a run', function (): void {
    it('carries the head and the merge base, which the proof of a new case switches the checkout to', function (): void {
        $input = proofRunInput([]);

        expect($input['verify'])->toMatchArray(['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml', 'head' => PROOF_SHA, 'merge_base' => str_repeat('c', 40),
            'checkout_path' => '/home/orbit/apps/orbit/workspace']);
    });

    it('carries keep_failed only for a proof that was started with it', function (array $proofInput, bool $carried): void {
        $input = proofRunInput($proofInput);

        expect(array_key_exists('keep_failed', $input))->toBe($carried);
        if ($carried) {
            expect($input['keep_failed'])->toBeTrue();
        }
    })->with([
        'asked for' => [['keep_failed' => true], true],
        'not mentioned' => [[], false],
        'false' => [['keep_failed' => false], false],
    ]);
});

describe('keep_failed', function (): void {
    it('is recorded on the proof only when it is asked for, so a proof without it has the input it always had', function (): void {
        Queue::fake();
        proofWorld(proofSource());

        $kept = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null, keepFailed: true);
        $plain = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);

        expect($kept->input)->toBe(['commit' => PROOF_SHA, 'all' => false, 'report_only' => false, 'caller_node_id' => null, 'ci_pool' => false, 'runs' => [], 'keep_failed' => true])
            ->and($plain->input)->toBe(['commit' => PROOF_SHA, 'all' => false, 'report_only' => false, 'caller_node_id' => null, 'ci_pool' => false, 'runs' => []]);
    });
});

it('derives the CI pool only from the authenticated active ci-runner Node', function (string $role, string $status, bool $ci): void {
    Queue::fake();
    proofWorld(proofSource());
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'runner', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.12', 'public_ssh_host' => '10.44.0.12', 'user' => 'orbit']));
    $node->roles()->create(['role' => $role, 'status' => $status]);
    $proof = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null, reportOnly: true, authenticatedCaller: $node);
    expect($proof->input)->toMatchArray(['caller_node_id' => $node->id, 'ci_pool' => $ci]);
})->with([['ci-runner', 'active', true], ['ci-runner', 'failed', false], ['app-dev', 'active', false]]);

it('keeps authenticated CI proofs waiting without children until capacity or a clear timeout', function (): void {
    proofWorldWithoutCapacity();
    $caller = Node::query()->where('name', 'swamp')->firstOrFail();
    $caller->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
    $proof = proofAdmitted(caller: $caller);
    expect($proof->status)->toBe(OperationStatus::Running)->and($proof->current_step)->toBe(ProofRunIntent::WaitStep)
        ->and(ProofRunSettlement::children($proof->id))->toHaveCount(0);
    $proof->forceFill(['created_at' => now()->subSeconds(ProofRunIntent::WaitSeconds + 1)])->save();
    app(ProofRunIntent::class)->admit($proof->id);
    expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.capacity_timeout');
});
