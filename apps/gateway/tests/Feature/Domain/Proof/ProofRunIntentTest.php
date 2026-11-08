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
use App\Domain\Proof\ProofReportOnlyProofs;
use App\Domain\Proof\ProofRunIntent;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStartCommit;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\Proof\AdmitProofRun;
use App\Jobs\Proof\CancelProofGroup;
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
function proofHostWithCopies(object $source, ?string $copiesCommit, int $maxVms = 6): string
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
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25', [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', $maxVms, 4, '8GiB', '20GiB')], '/pi', str_repeat('a', 64), ['gpt-6'], largeProjectIds: [$project->id]));
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
            ->and($proof->input)->toBe(['commit' => PROOF_SHA, 'all' => false, 'report_only' => false, 'caller_node_id' => null, 'ci_reserve' => false, 'runs' => []])
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

    it('keeps nothing when a command cannot start: no group, no run, and the proof fails with the reason', function (): void {
        config(['proof.execution' => true]);
        app(TaskExtensionState::class)->enable();
        $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $project->id, 'swamp.proof_club_api_key' => 'club-key']);
        $source = proofSource();
        $source->files = [new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')];
        proofWorld($source);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable')
            ->and(proofFailureDetail($proof))->toBe('Task VM: no host has room for another task VM outside the CI/refresh reserve.')
            ->and(Task::query()->count())->toBe(0)
            ->and(Operation::query()->where('kind', 'proof.command')->count())->toBe(0);
    });

    it('records a report-only run as such and fails it for capacity like any other run', function (): void {
        config(['proof.execution' => true]);
        app(TaskExtensionState::class)->enable();
        $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $project->id, 'swamp.proof_club_api_key' => 'club-key']);
        $source = proofSource();
        $source->files = [new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')];
        proofWorld($source);

        $proof = proofAdmitted(reportOnly: true);

        expect($proof->error_code)->toBe('tasks.capacity_unavailable')->and($proof->input['report_only'])->toBeTrue()
            ->and($proof->result['selected_commands'])->toBe(['firewall:allow'])
            ->and($proof->result['cases'])->toBe([])->and(Task::query()->count())->toBe(0);
    });

    it('admits report-only CI into the reserved slot instead of leaving it free', function (): void {
        $source = proofSource();
        $base = proofHostWithCopies($source, 'dddddddddddd0000000000000000000000000000', maxVms: 3);
        $source->ranges['dddddddddddd0000000000000000000000000000'] = new ProofCommitRange(GitHubComparisonStatus::Identical, new ProofChangedFiles(str_repeat('f', 40), [], true));
        $observed = null;
        TaskVm::created(static function (TaskVm $vm) use (&$observed): void {
            $observed = $vm->slot_number;
            throw new ResourceOperationException('test.slot_observed', 'Stop before preparing execution.', 409);
        });

        $ci = Node::query()->where('name', 'beast')->sole();
        $ci->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
        $proof = proofAdmitted(reportOnly: true, caller: $ci);

        Process::run(['rm', '-rf', $base]);
        expect($observed)->toBe(1)->and($proof->error_code)->toBe('test.slot_observed');
    });

    it('waits visibly for a busy CI reserve, then reports capacity with the selected commands and no cases', function (): void {
        $this->freezeTime();
        $source = proofSource();
        $base = proofHostWithCopies($source, 'dddddddddddd0000000000000000000000000000', maxVms: 3);
        $source->ranges['dddddddddddd0000000000000000000000000000'] = new ProofCommitRange(GitHubComparisonStatus::Identical, new ProofChangedFiles(str_repeat('f', 40), [], true));
        $host = Node::query()->where('name', 'beast')->sole();
        TaskVm::query()->create(['host_node_id' => $host->id, 'slot_number' => 1, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
            'name' => 'beast-tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.150']);

        $ci = Node::query()->where('name', 'beast')->sole();
        $ci->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
        $proof = proofAdmitted(reportOnly: true, caller: $ci);

        expect($proof->status)->toBe(OperationStatus::Running)->and($proof->current_step)->toBe(ProofRunIntent::WaitStep)
            ->and($proof->input['runs'])->toBe([])->and(Task::query()->count())->toBe(0);
        Queue::assertPushed(AdmitProofRun::class, fn (AdmitProofRun $job): bool => $job->operationId === $proof->id && $job->delay === ProofRunIntent::RetrySeconds);
        Queue::assertNotPushed(CancelProofGroup::class);
        $this->travel(ProofRunIntent::WaitSeconds + 1)->seconds();
        app(ProofRunIntent::class)->admit($proof->id);

        Process::run(['rm', '-rf', $base]);
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable')
            ->and($proof->result['selected_commands'])->toBe(['firewall:allow'])->and($proof->result['cases'])->toBe([]);
    });

    it('places the proof on a host only when its ready copies include every change of what the image carries up to the merge base', function (?string $copies, ?array $range, bool $skipped): void {
        $source = proofSource();
        $base = proofHostWithCopies($source, $copies);
        if ($copies !== null) {
            $source->ranges[$copies] = $range === null ? null : new ProofCommitRange(GitHubComparisonStatus::from($range[0]), new ProofChangedFiles(str_repeat('f', 40),
                array_map(static fn (string $path): ProofChangedFile => new ProofChangedFile($path, 'modified'), $range[1]), $range[2] ?? true));
        }

        $proof = proofAdmitted();

        Process::run(['rm', '-rf', $base]);
        $detail = proofFailureDetail($proof) ?? '';
        expect(str_contains($detail, 'no usable nested fleet seed are skipped: beast'))->toBe($skipped)
            ->and(str_contains($detail, 'playbooks, pins and lock files up to the merge base'))->toBe($skipped);
        if ($skipped) {
            expect($proof->error_code)->toBe('tasks.capacity_unavailable');
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
        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable')
            ->and($kept->refresh()->result['kept']['cancel_requested_at'])->toBeNull();
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('tells the caller what a refused proof waits for: the change the copies lack, and whether a refresh of the host is running', function (bool $running): void {
        $source = proofSource();
        $copies = 'aaaaaaaaaaaa0000000000000000000000000000';
        $base = proofHostWithCopies($source, $copies);
        $source->ranges[$copies] = new ProofCommitRange(GitHubComparisonStatus::Ahead, new ProofChangedFiles(str_repeat('f', 40), [new ProofChangedFile('swamp/ansible/task-vm-role.yml', 'modified')], true));
        $host = Node::query()->where('name', 'beast')->firstOrFail();
        $refresh = $running ? Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id, 'status' => OperationStatus::Running,
            'input' => [], 'triggered_by' => 'test']) : null;

        $proof = proofAdmitted();

        Process::run(['rm', '-rf', $base]);
        $detail = proofFailureDetail($proof) ?? '';
        expect($proof->error_code)->toBe('tasks.capacity_unavailable')
            ->and($detail)->toContain('beast: its ready copies come from release aaaaaaaaaaaa and lack a change of swamp/ansible/task-vm-role.yml; ')
            ->and($detail)->not->toContain('Refresh their task image');
        if ($refresh !== null) {
            expect($detail)->toContain("a refresh is running (operation {$refresh->id}), run the proof again when it ends.");
        } else {
            expect($detail)->toContain('no refresh is running; a release that changes the playbooks, pins or lock files starts one.');
        }
        // A refusal starts no refresh.
        expect(Operation::query()->where('kind', 'task_vm.image_refresh')->count())->toBe($running ? 2 : 1);
    })->with(['a refresh is running' => true, 'no refresh is running' => false]);
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

describe('a proof that cannot be placed while failed runs keep their VMs', function (): void {
    it('releases the kept VMs and waits, visibly, for the room instead of failing', function (): void {
        proofWorldWithoutCapacity();
        [$kept] = proofKeptRun();
        [$other] = proofKeptRun('firewall:list');

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Running)->and($proof->error_code)->toBeNull()->and($proof->current_step)->toBe(ProofRunIntent::WaitStep)
            ->and($kept->refresh()->result['kept']['cancel_requested_at'])->not->toBeNull()->and($other->refresh()->result['kept']['cancel_requested_at'])->not->toBeNull()
            ->and(Operation::query()->where('kind', 'proof.command')->where('input->proof_operation_id', $proof->id)->count())->toBe(0);
        Queue::assertPushed(CancelProofGroup::class, 2);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $kept->id && $job->trigger === 'proof-run');
        // The first job and one that tries again in a few seconds.
        Queue::assertPushed(AdmitProofRun::class, 2);
        Queue::assertPushed(AdmitProofRun::class, static fn (AdmitProofRun $job): bool => $job->delay === ProofRunIntent::RetrySeconds);
    });

    it('waits on while the VMs are on their way out, and asks for no second cancel', function (): void {
        proofWorldWithoutCapacity();
        proofKeptRun();
        $proof = proofAdmitted();

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Running)->and($proof->error_code)->toBeNull();
        Queue::assertPushed(CancelProofGroup::class, 1);
        Queue::assertPushed(AdmitProofRun::class, 3);
    });

    it('fails with the capacity code when the kept VMs are gone and the room is still short', function (): void {
        proofWorldWithoutCapacity();
        [, $group] = proofKeptRun();
        $proof = proofAdmitted();
        $group->update(['status' => TaskGroupStatus::Cancelled]);

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable');
    });

    it('stops waiting after the time that the watch of a proof with no runs allows', function (): void {
        proofWorldWithoutCapacity();
        proofKeptRun();
        $proof = proofAdmitted();
        $this->travel(ProofRunIntent::WaitSeconds + 1)->seconds();

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable')
            ->and(ProofRunIntent::WaitSeconds + ProofRunIntent::RetrySeconds + 60)->toBeLessThan(ProofRunWatch::AdmissionSeconds);
    });

    it('fails as before when no VM is kept', function (): void {
        proofWorldWithoutCapacity();

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable')->and($proof->current_step)->toBeNull();
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('does not release a kept VM for a report-only proof, which yields capacity', function (): void {
        proofWorldWithoutCapacity();
        [$kept] = proofKeptRun();

        $proof = proofAdmitted(reportOnly: true);

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable')
            ->and($kept->refresh()->result['kept']['cancel_requested_at'])->toBeNull();
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('leaves a proof that was cancelled while it waited cancelled, and releases nothing for it', function (): void {
        Queue::fake();
        proofWorldWithoutCapacity();
        [$kept] = proofKeptRun();
        $proof = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);
        $proof->update(['status' => OperationStatus::Cancelled]);

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Cancelled)->and($kept->refresh()->result['kept']['cancel_requested_at'])->toBeNull();
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('does not hold the other kept VMs back when one of them cannot be released', function (): void {
        proofWorldWithoutCapacity();
        [$broken] = proofKeptRun();
        [$fine] = proofKeptRun('firewall:list');
        $armed = true;
        Operation::saving(static function (Operation $operation) use (&$armed, $broken): void {
            if ($armed && $operation->id === $broken->id) {
                throw new RuntimeException('The write failed.');
            }
        });

        try {
            $proof = proofAdmitted();
        } finally {
            $armed = false;
        }

        expect($proof->status)->toBe(OperationStatus::Running)->and($fine->refresh()->result['kept']['cancel_requested_at'])->not->toBeNull();
        Queue::assertPushed(CancelProofGroup::class, 1);
    });

    it('does not ask for a keep that is more than a day past its time', function (): void {
        proofWorldWithoutCapacity();
        [$old] = proofKeptRun();
        $old->update(['result' => ['kept' => [...$old->result['kept'], 'keep_until' => now()->subDays(2)->utc()->format('Y-m-d\TH:i:s\Z')]]]);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable');
        Queue::assertNotPushed(CancelProofGroup::class);
    });
    it('releases only the runs that kept a VM, never another running group of the same command', function (): void {
        proofWorldWithoutCapacity();
        [$kept] = proofKeptRun();
        [$stranger] = proofKeptRun();
        // A running run of another proof, with no record of a kept VM: its group is not this rule's.
        $stranger->update(['result' => ['cases' => []]]);

        proofAdmitted();

        Queue::assertPushed(CancelProofGroup::class, 1);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $kept->id);
    });
});
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

        expect($kept->input)->toBe(['commit' => PROOF_SHA, 'all' => false, 'report_only' => false, 'caller_node_id' => null, 'ci_reserve' => false, 'runs' => [], 'keep_failed' => true])
            ->and($plain->input)->toBe(['commit' => PROOF_SHA, 'all' => false, 'report_only' => false, 'caller_node_id' => null, 'ci_reserve' => false, 'runs' => []]);
    });
});

/**
 * A proof of an earlier commit with one run on its group: the report-only job of a pull request, or a normal proof, while it runs.
 *
 * @return array{0: Operation, 1: Operation, 2: Task}
 */
function proofRunningElsewhere(bool $reportOnly = true, bool $withRun = true): array
{
    $node = Node::query()->firstOrFail();
    $earlier = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id, 'status' => OperationStatus::Running,
        'input' => ['commit' => str_repeat('b', 40), 'report_only' => $reportOnly, 'runs' => []], 'state' => [], 'sequence' => 1, 'attempts' => 0]);
    $group = Task::query()->create(['title' => 'Proof firewall:allow', 'brief' => 'Prove it', 'status' => TaskGroupStatus::Running]);
    $run = Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id, 'status' => OperationStatus::Running,
        'input' => ['proof_operation_id' => $withRun ? $earlier->id : 0, 'group_id' => $group->id], 'state' => [], 'sequence' => 1, 'attempts' => 0]);

    return [$earlier, $run, $group];
}

describe('a proof that cannot be placed while report-only proofs run', function (): void {
    it('cancels the report-only proofs, with their runs and groups, and waits, visibly, for the room', function (): void {
        proofWorldWithoutCapacity();
        [$reportOnly, $run] = proofRunningElsewhere();

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Running)->and($proof->error_code)->toBeNull()->and($proof->current_step)->toBe(ProofRunIntent::WaitStep)
            ->and($reportOnly->refresh()->status)->toBe(OperationStatus::Cancelled)->and($reportOnly->cancel_requested_at)->not->toBeNull();
        Queue::assertPushed(CancelProofGroup::class, 1);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id && $job->trigger === 'proof-run');
        Queue::assertPushed(AdmitProofRun::class, static fn (AdmitProofRun $job): bool => $job->delay === ProofRunIntent::RetrySeconds);
    });

    it('waits on while their VMs are on their way out, and asks again for the keyed cancel of their group', function (): void {
        proofWorldWithoutCapacity();
        proofRunningElsewhere();
        $proof = proofAdmitted();

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Running)->and($proof->error_code)->toBeNull();
        // The first cancel, and the same keyed cancel asked again on the second try.
        Queue::assertPushed(CancelProofGroup::class, 2);
    });

    it('fails with the capacity code when their groups are gone and the room is still short', function (): void {
        proofWorldWithoutCapacity();
        [, , $group] = proofRunningElsewhere();
        $proof = proofAdmitted();
        $group->update(['status' => TaskGroupStatus::Cancelled]);

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable');
    });

    it('does not cancel a report-only proof for another report-only proof, which yields capacity', function (): void {
        proofWorldWithoutCapacity();
        [$reportOnly] = proofRunningElsewhere();

        $proof = proofAdmitted(reportOnly: true);

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable')->and($reportOnly->refresh()->status)->toBe(OperationStatus::Running);
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('cancels only report-only proofs: a normal proof that runs keeps its VMs', function (): void {
        proofWorldWithoutCapacity();
        [$normal] = proofRunningElsewhere(reportOnly: false);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable')->and($normal->refresh()->status)->toBe(OperationStatus::Running);
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('leaves a report-only proof that has no runs yet to its admission', function (): void {
        proofWorldWithoutCapacity();
        [$admitting] = proofRunningElsewhere(withRun: false);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($admitting->refresh()->status)->toBe(OperationStatus::Running);
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('does not hold the other report-only proofs back when one of them cannot be cancelled', function (): void {
        proofWorldWithoutCapacity();
        [$broken] = proofRunningElsewhere();
        [$fine] = proofRunningElsewhere();
        Operation::saving(static function (Operation $operation) use ($broken): void {
            if ($operation->id === $broken->id && $operation->isDirty('status')) {
                throw new RuntimeException('The write failed.');
            }
        });

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Running)->and($fine->refresh()->status)->toBe(OperationStatus::Cancelled);
    });
});

describe('a proof that cannot be placed while a report-only proof is on its way out', function (): void {
    it('counts a report-only proof that has ended but whose group is still there, and asks for its cancel again', function (): void {
        proofWorldWithoutCapacity();
        [$ended, $run] = proofRunningElsewhere();
        $ended->update(['status' => OperationStatus::Cancelled, 'finished_at' => now()->subMinute()]);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Running)->and($proof->current_step)->toBe(ProofRunIntent::WaitStep);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });

    it('does not look at a report-only proof that ended long ago', function (): void {
        proofWorldWithoutCapacity();
        [$ended] = proofRunningElsewhere();
        $ended->update(['status' => OperationStatus::Cancelled, 'finished_at' => now()->subMinutes(ProofReportOnlyProofs::DrainMinutes + 1)]);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('tasks.capacity_unavailable');
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('waits and tries again when the only report-only proof cannot be cancelled now', function (): void {
        proofWorldWithoutCapacity();
        [$broken] = proofRunningElsewhere();
        Operation::saving(static function (Operation $operation) use ($broken): void {
            if ($operation->id === $broken->id && $operation->isDirty('status')) {
                throw new RuntimeException('The write failed.');
            }
        });

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Running)->and($proof->error_code)->toBeNull()->and($proof->current_step)->toBe(ProofRunIntent::WaitStep);
        Queue::assertPushed(AdmitProofRun::class, static fn (AdmitProofRun $job): bool => $job->delay === ProofRunIntent::RetrySeconds);
    });
});

/** Two shared hosts at twelve budget units, with all six public Large places occupied. */
function proofReservedFleet(object $source): array
{
    $copies = 'dddddddddddd0000000000000000000000000000';
    $base = proofHostWithCopies($source, $copies, maxVms: 12);
    $source->ranges[$copies] = new ProofCommitRange(GitHubComparisonStatus::Identical, new ProofChangedFiles(str_repeat('f', 40), [], true));
    $beast = Node::query()->where('name', 'beast')->sole();
    $shark = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'shark', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.11', 'wireguard_ip' => '10.44.0.11', 'user' => 'orbit']));
    $build = TaskVmImageBuild::query()->where('host_node_id', $beast->id)->sole()->replicate();
    $build->host_node_id = $shark->id;
    $build->save();
    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $shark->id, 'node_id' => $shark->id,
        'status' => OperationStatus::Succeeded, 'input' => ['commit' => $copies], 'result' => ['refreshed' => true, 'host_node_id' => $shark->id, 'build_id' => $build->id], 'triggered_by' => 'test']);
    $settings = app(TaskVmSettings::class);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $settings->devClusterId, $settings->wireguardRange,
        [...$settings->hosts, new TaskVmHost($shark->id, 'orbit-tasks', 'orbittask0', '10.252.11.0/24', 12, 6, '12GiB', '20GiB')], '/pi', str_repeat('a', 64), ['gpt-6']));
    foreach ([$beast, $shark] as $index => $host) {
        foreach ([2, 3, 4] as $number) {
            TaskVm::query()->create(['host_node_id' => $host->id, 'slot_number' => $number, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
                'name' => $host->name.'-tvm-'.$number, 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.'.(140 + $index * 4 + $number)]);
        }
    }
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $operator = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'operator', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $beast->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
    foreach ([$beast, $shark, $operator] as $caller) {
        $caller->accessibleNodes()->attach($gateway);
    }
    Queue::fake();

    return [$base, $beast, $shark, $operator];
}

/** Authenticate through the peer IP, then inspect the queued record independently of the request. */
function proofHttpQueued(Node $caller, bool $reportOnly = false, ?string $key = null): Operation
{
    $response = test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->withHeader('Idempotency-Key', $key ?? fake()->uuid())
        ->postJson('/api/v1/proof/run', ['commit' => PROOF_SHA, 'report_only' => $reportOnly]);
    $response->assertAccepted();

    return Operation::query()->findOrFail($response->json('data.id'));
}

describe('authenticated proof capacity at the HTTP boundary', function (): void {
    it('persists CI eligibility before queueing and uses the reserve after the request ends', function (): void {
        [$base, $ci] = proofReservedFleet(proofSource());
        try {
            $proof = proofHttpQueued($ci, reportOnly: true);
            Queue::assertPushed(AdmitProofRun::class, fn (AdmitProofRun $job): bool => $job->operationId === $proof->id);
            // Eligibility is a server-authenticated admission snapshot, not the queue worker's identity or a new role lookup.
            $ci->roles()->where('role', RoleName::CiRunner)->delete();
            $observed = null;
            TaskVm::created(static function (TaskVm $vm) use (&$observed): void {
                $observed = [$vm->host_node_id, $vm->slot_number];
                throw new ResourceOperationException('test.slot_observed', 'Stop before execution.', 409);
            });
            app(ProofRunIntent::class)->admit($proof->id);
            expect($observed)->toBe([$ci->id, 1])->and($proof->refresh()->error_code)->toBe('test.slot_observed');
            expect($proof->input)->toMatchArray(['caller_node_id' => $ci->id, 'ci_reserve' => true]);
        } finally {
            Process::run(['rm', '-rf', $base]);
        }
    });

    it('refuses operator and task-node proofs outside the reserves regardless of report-only mode', function (string $identity, bool $reportOnly): void {
        [$base, , $taskNode, $operator] = proofReservedFleet(proofSource());
        try {
            $taskNode->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
            $caller = $identity === 'operator' ? $operator : $taskNode;
            $this->freezeTime();
            $proof = proofHttpQueued($caller, $reportOnly);
            app(ProofRunIntent::class)->admit($proof->id);
            if ($reportOnly) {
                expect($proof->refresh()->current_step)->toBe(ProofRunIntent::WaitStep);
                $this->travel(ProofRunIntent::WaitSeconds + 1)->seconds();
                app(ProofRunIntent::class)->admit($proof->id);
            }
            expect($proof->refresh()->error_code)->toBe('tasks.capacity_unavailable')->and(TaskVm::query()->count())->toBe(6);
            expect($proof->input)->toMatchArray(['caller_node_id' => $caller->id, 'ci_reserve' => false]);
        } finally {
            Process::run(['rm', '-rf', $base]);
        }
    })->with([['operator', false], ['operator', true], ['task', false], ['task', true]]);

    it('never cancels authenticated CI when an operator proof is refused', function (): void {
        [$base, $ci, , $operator] = proofReservedFleet(proofSource());
        try {
            $protected = proofHttpQueued($ci, reportOnly: true);
            foreach (app(TaskVmSettings::class)->hosts as $index => $host) {
                TaskVm::query()->create(['host_node_id' => $host->nodeId, 'slot_number' => 1, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
                    'name' => 'occupied-reserve-'.$index, 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.'.(155 + $index)]);
            }
            [, $run] = proofRunningElsewhere();
            $run->update(['input' => ['proof_operation_id' => $protected->id, 'group_id' => $run->target_id]]);
            $proof = proofHttpQueued($operator);
            app(ProofRunIntent::class)->admit($proof->id);
            expect($protected->refresh()->status)->toBe(OperationStatus::Running)->and($protected->cancel_requested_at)->toBeNull();
            expect($proof->refresh()->error_code)->toBe('tasks.capacity_unavailable');
            Queue::assertNotPushed(CancelProofGroup::class);
        } finally {
            Process::run(['rm', '-rf', $base]);
        }
    });

    it('binds a CI idempotency key to the authenticated caller', function (): void {
        [$base, $ci, , $operator] = proofReservedFleet(proofSource());
        try {
            $key = fake()->uuid();
            $proof = proofHttpQueued($ci, key: $key);
            $this->withServerVariables(['REMOTE_ADDR' => $operator->wireguard_ip])->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/proof/run', ['commit' => PROOF_SHA, 'report_only' => false])->assertConflict();
            expect($proof->refresh()->input['ci_reserve'])->toBeTrue();
        } finally {
            Process::run(['rm', '-rf', $base]);
        }
    });

    it('does not let a task start take a reserve even when its caller runs CI', function (): void {
        [$base, $ci] = proofReservedFleet(proofSource());
        try {
            app()->instance(TaskStartCommit::class, new class implements TaskStartCommit
            {
                public function resolve(Project $project): string
                {
                    return PROOF_SHA;
                }
            });
            $group = Task::query()->create(['project_id' => config('proof.project'), 'title' => 'Task', 'brief' => 'Build it', 'status' => TaskGroupStatus::Todo]);
            $this->withServerVariables(['REMOTE_ADDR' => $ci->wireguard_ip])->withHeader('Idempotency-Key', fake()->uuid())
                ->postJson('/api/v1/tasks/'.$group->id.'/start')->assertConflict()->assertJsonPath('error.code', 'tasks.capacity_unavailable');
            expect(TaskVm::query()->count())->toBe(6);
        } finally {
            Process::run(['rm', '-rf', $base]);
        }
    });
});

it('does not grant CI eligibility from a name, inactive role, or request flag', function (string $roleState): void {
    [$base, , , $operator] = proofReservedFleet(proofSource());
    try {
        $operator->update(['name' => 'orbit-proof-ci-runner']);
        $operator->roles()->create(['role' => RoleName::CiRunner, 'status' => $roleState]);
        $proof = proofHttpQueued($operator);
        expect($proof->input['ci_reserve'])->toBeFalse();
        $this->withServerVariables(['REMOTE_ADDR' => $operator->wireguard_ip])->withHeader('Idempotency-Key', fake()->uuid())
            ->postJson('/api/v1/proof/run', ['commit' => PROOF_SHA, 'ci_reserve' => true, 'caller_node_id' => 9])->assertUnprocessable();
    } finally {
        Process::run(['rm', '-rf', $base]);
    }
})->with(['provisioning', 'failed']);

it('records CI-only host intent through node:update and rejects non-boolean policy', function (): void {
    [$base, , $sabre, $operator] = proofReservedFleet(proofSource());
    try {
        $operator->accessibleNodes()->attach($sabre);
        $host = ['cidr' => '10.252.12.0/24', 'max_vms' => 6, 'cpus' => 6, 'memory' => '12GiB', 'ci_only' => true];
        $this->withServerVariables(['REMOTE_ADDR' => $operator->wireguard_ip])->withHeader('Idempotency-Key', fake()->uuid())
            ->patchJson('/api/v1/nodes/'.$sabre->id, ['task_vms' => $host])->assertOk()->assertJsonPath('data.settings.task_vms.ci_only', true);
        expect($sabre->refresh()->settings['task_vms']['ci_only'])->toBeTrue();
        $this->withHeader('Idempotency-Key', fake()->uuid())->patchJson('/api/v1/nodes/'.$sabre->id, ['task_vms' => [...$host, 'ci_only' => 'true']])->assertUnprocessable();
        expect($sabre->refresh()->settings['task_vms']['ci_only'])->toBeTrue();
    } finally {
        Process::run(['rm', '-rf', $base]);
    }
});

it('places an authenticated CI proof on CI-only capacity before a shared reserve', function (): void {
    [$base, $ci, , $operator] = proofReservedFleet(proofSource());
    try {
        $sabre = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'sabre', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.12', 'wireguard_ip' => '10.44.0.12', 'user' => 'orbit']));
        $build = TaskVmImageBuild::query()->where('host_node_id', $ci->id)->sole()->replicate();
        $build->host_node_id = $sabre->id;
        $build->save();
        Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $sabre->id, 'node_id' => $sabre->id,
            'status' => OperationStatus::Succeeded, 'input' => ['commit' => 'dddddddddddd0000000000000000000000000000'],
            'result' => ['refreshed' => true, 'host_node_id' => $sabre->id, 'build_id' => $build->id], 'triggered_by' => 'test']);
        $settings = app(TaskVmSettings::class);
        app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $settings->devClusterId, $settings->wireguardRange,
            [...$settings->hosts, new TaskVmHost($sabre->id, 'orbit-tasks', 'orbittask0', '10.252.12.0/24', 6, 6, '12GiB', '20GiB', ciOnly: true)], '/pi', str_repeat('a', 64), ['gpt-6']));
        $operatorProof = proofHttpQueued($operator);
        app(ProofRunIntent::class)->admit($operatorProof->id);
        expect($operatorProof->refresh()->error_code)->toBe('tasks.capacity_unavailable')->and(TaskVm::query()->count())->toBe(6);
        $proof = proofHttpQueued($ci);
        $observed = null;
        TaskVm::created(static function (TaskVm $vm) use (&$observed): void {
            $observed = [$vm->host_node_id, $vm->slot_number];
            throw new ResourceOperationException('test.slot_observed', 'Stop before execution.', 409);
        });
        app(ProofRunIntent::class)->admit($proof->id);
        expect($observed)->toBe([$sabre->id, 1]);
    } finally {
        Process::run(['rm', '-rf', $base]);
    }
});
