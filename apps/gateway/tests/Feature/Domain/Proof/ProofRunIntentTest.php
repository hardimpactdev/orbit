<?php

declare(strict_types=1);

use App\Data\Operations\OperationData;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubReadFailure;
use App\Domain\GitHub\GitHubReadFailureKind;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofBatchPlan;
use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofCommitRange;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofCommitTrust;
use App\Domain\Proof\ProofCopies;
use App\Domain\Proof\ProofMergeBaseReplay;
use App\Domain\Proof\ProofPullRequest;
use App\Domain\Proof\ProofRunIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskVmRemoved;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ApiOperationTool;
use App\Http\Mcp\ToolManifest;
use App\Jobs\Proof\AdmitProofRun;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Queue;

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

        /** What a read throws while GitHub is down; a generic outage when null. */
        public ?GitHubApiException $failure = null;

        public array $documents = [];

        public int $reads = 0;

        public array $checkedCommits = [];

        public array $pathSets = [];

        public function commitExists(string $sha): bool
        {
            $this->reads++;
            $this->checkedCommits[] = $sha;

            return $this->down ? throw $this->failure ?? GitHubApiException::unavailable() : $this->exists;
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
            if (array_key_exists($sha.':'.$path, $this->documents)) {
                return $this->documents[$sha.':'.$path];
            }

            return match ($path) {
                'swamp/proof/selection.txt' => $this->list,
                'swamp/proof/batches.yaml' => "batches:\n  fresh-fleet: {summary: Fresh}\n",
                default => "command: firewall:allow\ncases:\n  - {id: allow, batch: fresh-fleet}\n",
            };
        }

        public ?array $selection = null;

        public array $selections = [];

        public function caseSelection(string $base, string $head): ?array
        {
            return $this->selections[$head] ?? $this->selection;
        }

        public function proofPaths(string $sha): array
        {
            return $this->pathSets[$sha] ?? $this->paths;
        }

        /** @var array<string,ProofCommitRange|null> release commit => how the merge base stands to it; an unknown commit is null */
        public array $ranges = [];

        public ?array $history = null;

        public function history(string $base, string $head): ?array
        {
            return $this->history;
        }

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

    it('refuses with a code that says what the caller should do, and keeps the reason of the failed read', function (?GitHubReadFailure $failure, string $code, int $status, string $message): void {
        $source = proofSource();
        $source->down = true;
        $source->failure = $failure === null ? GitHubApiException::tokenRefused(403, 'Resource not accessible by integration') : GitHubApiException::readFailed($failure);

        $refusal = proofRefused(fn () => app(ProofCommitTrust::class, ['source' => $source])->assert(PROOF_SHA));

        expect([$refusal->errorCode, $refusal->status, $refusal->getMessage()])->toBe([$code, $status, $message]);
    })->with([
        'transient' => [new GitHubReadFailure(GitHubReadFailureKind::Transient, 'commit', transport: 'ConnectionException'), 'proof.github_unavailable', 503,
            'GitHub could not be read, so the commit cannot be checked. The commit read failed with ConnectionException. Run the command again.'],
        'rate limited' => [new GitHubReadFailure(GitHubReadFailureKind::RateLimited, 'commit', 403, resetAt: new DateTimeImmutable('2026-11-15T06:00:00+00:00')), 'proof.github_rate_limited', 429,
            'GitHub could not be read, so the commit cannot be checked. The commit read hit the GitHub rate limit (HTTP 403), which resets at 2026-11-15T06:00:00+00:00. Run the command again after the reset.'],
        'refused' => [new GitHubReadFailure(GitHubReadFailureKind::Refused, 'commit', 401), 'proof.github_refused', 502,
            'GitHub could not be read, so the commit cannot be checked. The commit read answered HTTP 401. An operator must check the Gateway GitHub App and its access to the repository.'],
        'token refused' => [null, 'proof.github_refused', 502,
            'GitHub could not be read, so the commit cannot be checked. GitHub refused the Project token request (403): Resource not accessible by integration. An operator must check the Gateway GitHub App and its access to the repository.'],
        'not found' => [new GitHubReadFailure(GitHubReadFailureKind::NotFound, 'commit branches', 404), 'proof.github_not_found', 422,
            'GitHub could not be read, so the commit cannot be checked. The commit branches read answered HTTP 404. Running it again does not help.'],
    ]);

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
function proofAdmitted(bool $all = false, bool $reportOnly = false, ?Node $caller = null, ?string $base = null): Operation
{
    Queue::fake();
    $proof = app(ProofRunIntent::class)->start(PROOF_SHA, $all, 'tester', null, reportOnly: $reportOnly, authenticatedCaller: $caller, base: $base);
    app(ProofRunIntent::class)->admit($proof->id);

    return $proof->refresh();
}

/** The message of the failure, as the Operation shows it. */
function proofFailureDetail(Operation $proof): ?string
{
    return $proof->state['native_failure']['detail'] ?? null;
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
            ->and($proof->started_at)->toBeNull()
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

    it('lists a cases file with no case as uncovered, for --all and when its command code changed', function (): void {
        config(['proof.execution' => false]);
        $source = proofSource();
        $source->paths = ['swamp/proof/database/import.cases.yaml', 'swamp/proof/firewall/allow.cases.yaml'];
        $source->documents = [PROOF_SHA.':swamp/proof/database/import.cases.yaml' => "command: database:import\nuncovered:\n  main: no fixture\ncases: []\n"];
        $source->files = [new ProofChangedFile('docs/a.md', 'modified')];
        proofWorld($source);
        $noCase = ['path' => 'swamp/proof/database/import.cases.yaml', 'reason' => 'none: no case yet'];

        $all = proofAdmitted(all: true);

        expect($all->error_code)->toBe('proof.execution_disabled')
            ->and(array_column($all->input['cases'], 'file'))->toBe(['swamp/proof/firewall/allow.cases.yaml'])
            ->and($all->input['selected_commands'])->toBe(['firewall:allow'])
            ->and($all->input['uncovered'])->toBe([$noCase]);

        // The command's own code changed: its empty file selects nothing, so the proof has nothing to prove.
        $source->list .= "apps/cli/app/Commands/Databases/**  database:import\n";
        $source->files = [new ProofChangedFile('apps/cli/app/Commands/Databases/ImportDatabaseCommand.php', 'modified')];
        $code = proofAdmitted();

        expect($code->status)->toBe(OperationStatus::Succeeded)
            ->and($code->result['outcome'])->toBe('nothing_to_prove')
            ->and($code->result['uncovered'])->toBe([$noCase]);
    });

    // A proof runs only in copies: a proof Project whose tasks do not run as copies fails by the removed task VM runtime, with no group and no wait.
    it('fails a proof of a Project whose tasks do not run as copies by the removed task VM runtime, with no group and no waiting run', function (bool $reportOnly, bool $ci): void {
        proofWorldWithoutCopies();
        $caller = Node::query()->where('name', 'swamp')->firstOrFail();
        if ($ci) {
            $caller->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
        }
        $proof = proofAdmitted(reportOnly: $reportOnly, caller: $ci ? $caller : null);
        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe(TaskVmRemoved::Code)
            ->and(Task::query()->count())->toBe(0)
            ->and(Operation::query()->where('status', OperationStatus::Waiting)->count())->toBe(0);
    })->with(['operator' => [false, false], 'report only' => [true, false], 'CI' => [true, true]]);

    it('fails a proof whose proof Project is missing by the same refusal, with no group', function (): void {
        proofWorldWithoutCopies();
        config(['proof.project' => 999]);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe(TaskVmRemoved::Code)->and(Task::query()->count())->toBe(0);
    });

    // The refusal is the proof's own: it does not wait behind a working proof, as the task VM admission did before it refused.
    it('refuses a proof of a Project whose tasks do not run as copies at once, even while another proof works', function (): void {
        proofWorldWithoutCopies();
        $project = Project::query()->sole();
        $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Proof', 'brief' => 'Brief', 'status' => 'running']);
        $working = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => 1, 'node_id' => 1, 'status' => OperationStatus::Running,
            'input' => ['commit' => PROOF_SHA, 'runs' => [['command' => 'firewall:allow', 'file' => 'f', 'group_id' => $group->id, 'operation_id' => 1]]]]);
        Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => 1, 'status' => OperationStatus::Running,
            'input' => ['proof_operation_id' => $working->id, 'command' => 'firewall:allow', 'file' => 'f']]);

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe(TaskVmRemoved::Code)
            ->and($proof->current_step)->toBeNull()->and(Operation::query()->where('kind', 'proof.command')->count())->toBe(1);
    });

    it('fails the proof at admission with the reason of the failed read on the Operation', function (): void {
        proofWorld($source = proofSource());
        $source->down = true;
        $source->failure = GitHubApiException::readFailed(new GitHubReadFailure(GitHubReadFailureKind::RateLimited, 'commit', 403, resetAt: new DateTimeImmutable('2026-11-15T06:00:00+00:00')));

        $proof = proofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.github_rate_limited')
            ->and(proofFailureDetail($proof))->toBe('GitHub could not be read, so the commit cannot be checked. The commit read hit the GitHub rate limit (HTTP 403), which resets at 2026-11-15T06:00:00+00:00. Run the command again after the reset.')
            ->and(OperationData::fromModel($proof)->error)->toMatchArray(['code' => 'proof.github_rate_limited', 'detail' => proofFailureDetail($proof)]);
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

/** A proof Project whose tasks do not run as copies: no nested fleet and no container default. */
function proofWorldWithoutCopies(): void
{
    config(['proof.execution' => true]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['proof.project' => $project->id]);
    $source = proofSource();
    $source->files = [new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')];
    proofWorld($source);
}

describe('the merge-base replay fields', function (): void {
    // Kept for the port of the replay to copies: the fields name exactly what the can-fail chain of proof-fleet-verify reads.
    it('names the head, the merge base and the checkout that the can-fail jobs of proof-fleet-verify read', function (): void {
        $fields = ProofMergeBaseReplay::fields('/srv/workspace', PROOF_SHA, str_repeat('c', 40));
        $workflow = file_get_contents(dirname(base_path(), 2).'/swamp/workflows/workflow-proof-fleet-verify.yaml');

        expect($fields)->toBe(['checkout_path' => '/srv/workspace', 'head' => PROOF_SHA, 'merge_base' => str_repeat('c', 40)]);
        foreach (['inputs.intent.?head', 'inputs.intent.?merge_base', 'inputs.intent.checkout_path'] as $read) {
            expect($workflow)->toContain($read);
        }
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

describe('case definition selection at admission', function (): void {
    it('records only the changed case and its reason, or succeeds with no VM when only a case is deleted', function (string $change, array $expected): void {
        config(['proof.execution' => false]);
        $source = proofSource();
        $file = 'swamp/proof/firewall/allow.cases.yaml';
        $source->files = [new ProofChangedFile($file, 'modified')];
        $before = "command: firewall:allow\ncases:\n  - {id: a, batch: fresh-fleet, summary: old}\n  - {id: b, batch: fresh-fleet}\n";
        $after = $change === 'changed' ? str_replace('summary: old', 'summary: new', $before) : "command: firewall:allow\ncases:\n  - {id: b, batch: fresh-fleet}\n";
        $source->documents[str_repeat('a', 40).':'.$file] = $before;
        $source->documents[PROOF_SHA.':'.$file] = $after;
        $source->selection = ['version' => 1, 'base' => str_repeat('a', 40), 'head' => PROOF_SHA,
            'files' => [['command' => 'firewall:allow', 'file' => $file, 'only' => array_keys($expected), 'reasons' => $expected]]];
        proofWorld($source);
        $proof = proofAdmitted();
        expect($proof->input['runs'])->toBe([]);
        if ($expected === []) {
            expect($proof->status)->toBe(OperationStatus::Succeeded)->and($proof->result['outcome'])->toBe('nothing_to_prove');
        } else {
            expect($proof->input['cases'][0]['only'])->toBe(['a'])->and($proof->input['cases'][0]['reasons'])->toBe($expected)
                ->and($proof->error_code)->toBe('proof.execution_disabled');
        }
    })->with([['changed', ['a' => 'changed']], ['deleted', []]]);
});

it('records the runner selection for both P1 regressions without a successful no-proof result', function (string $name): void {
    config(['proof.execution' => false]);
    $fixtures = json_decode(file_get_contents(dirname(__DIR__, 6).'/tools/fixtures/proof-case-selection.json'), true, flags: JSON_THROW_ON_ERROR);
    $test = array_first(array_filter($fixtures['tests'], static fn (array $test): bool => $test['name'] === $name));
    $source = proofSource();
    $file = 'swamp/proof/firewall/allow.cases.yaml';
    $source->files = [new ProofChangedFile($file, 'modified')];
    $source->documents[str_repeat('a', 40).':'.$file] = str_replace('demo:run', 'firewall:allow', $test['before']);
    $source->documents[PROOF_SHA.':'.$file] = str_replace('demo:run', 'firewall:allow', $test['after']);
    $source->documents[PROOF_SHA.':swamp/proof/batches.yaml'] = $test['after_batches'];
    $source->selection = ['version' => 1, 'base' => str_repeat('a', 40), 'head' => PROOF_SHA, 'files' => [
        ['command' => 'firewall:allow', 'file' => $file, 'only' => array_keys($test['expected']), 'reasons' => $test['expected']],
    ]];
    proofWorld($source);
    $proof = proofAdmitted();
    expect($proof->input['cases'][0]['only'])->toBe(['b'])
        ->and($proof->input['cases'][0]['reasons'])->toBe($test['expected'])
        ->and($proof->error_code)->toBe('proof.execution_disabled');
})->with(['list fixture parameter expands referenced vars', 'numeric YAML spelling follows runner types']);

it('selects every case when the runner selection is unavailable', function (): void {
    config(['proof.execution' => false]);
    $source = proofSource();
    $source->files = [new ProofChangedFile('swamp/proof/firewall/allow.cases.yaml', 'modified')];
    proofWorld($source);
    $proof = proofAdmitted();
    expect($proof->input['cases'][0])->not->toHaveKey('only')
        ->and($proof->input['cases'][0]['reasons'])->toBe(['*' => 'selection uncertain'])
        ->and($proof->error_code)->toBe('proof.execution_disabled');
});

it('honors validated empty execution coverage alongside another selected command', function (string $artifact, bool $all): void {
    config(['proof.execution' => false]);
    $source = proofSource();
    $migrateFile = 'swamp/proof/instance/migrate.cases.yaml';
    $source->list .= "swamp/ansible/instance-migrate.yml instance:migrate\n";
    $source->files = [new ProofChangedFile('swamp/ansible/instance-migrate.yml', 'modified'),
        new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')];
    $source->paths[] = $migrateFile;
    $source->selection = ['version' => 1, 'base' => str_repeat('a', 40), 'head' => $artifact === 'stale' ? str_repeat('f', 40) : PROOF_SHA,
        'files' => [
            ['command' => 'instance:migrate', 'file' => $migrateFile, 'only' => [], 'reasons' => []],
            ['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml', 'only' => ['allow'], 'reasons' => ['allow' => 'command code changed']],
        ]];
    if ($artifact === 'missing') {
        $source->selection = null;
    }
    proofWorld($source);
    $proof = proofAdmitted(all: $all);

    expect($proof->error_code)->toBe('proof.execution_disabled')->and($proof->input['runs'])->toBe([]);
    if ($artifact === 'valid' && ! $all) {
        expect($proof->input['cases'])->toBe([
            ['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml', 'only' => ['allow'], 'reasons' => ['allow' => 'command code changed']],
        ]);
    } else {
        expect(array_column($proof->input['cases'], 'command'))->toContain('firewall:allow', 'instance:migrate');
        foreach ($proof->input['cases'] as $case) {
            expect($case)->not->toHaveKey('only');
        }
    }
})->with([['valid', false], ['stale', false], ['missing', false], ['valid', true]]);

it('admits a waiting proof from its retry job, and no other status', function (): void {
    proofWorld(proofSource());
    Queue::fake();
    $waiting = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);
    $waiting->update(['status' => OperationStatus::Waiting, 'current_step' => ProofCopies::WaitStep]);
    $queued = app(ProofRunIntent::class)->start(PROOF_SHA, false, 'tester', null);
    $queued->update(['status' => OperationStatus::Queued]);

    app(ProofRunIntent::class)->admit($waiting->id);
    app(ProofRunIntent::class)->admit($queued->id);

    expect($waiting->refresh()->input)->toHaveKey('cases')->and($queued->refresh()->input)->not->toHaveKey('cases');
});

it('unions per-commit selections despite an empty net diff and drops IDs gone at the tested SHA', function (): void {
    config(['proof.execution' => false]);
    $source = proofSource();
    $source->branches = ['main'];
    $base = str_repeat('a', 40);
    $middle = str_repeat('c', 40);
    $file = 'swamp/proof/firewall/allow.cases.yaml';
    $source->history = [$middle => new ProofChangedFiles($base, [new ProofChangedFile($file, 'modified')], true),
        PROOF_SHA => new ProofChangedFiles($middle, [new ProofChangedFile($file, 'modified')], true)];
    foreach ([$middle => [$base, ['first', 'removed']], PROOF_SHA => [$middle, ['second[one]']]] as $sha => [$parent, $ids]) {
        $source->selections[$sha] = ['version' => 1, 'base' => $parent, 'head' => $sha, 'files' => [
            ['command' => 'firewall:allow', 'file' => $file, 'only' => $ids, 'reasons' => array_fill_keys($ids, 'changed')],
        ]];
    }
    $source->documents[PROOF_SHA.':'.$file] = "command: firewall:allow\ncases:\n  - {id: first, batch: fresh-fleet}\n  - {id: second, batch: fresh-fleet, variants: [{name: one}, {name: two}]}\n";
    proofWorld($source);

    $proof = proofAdmitted(base: $base);

    expect($proof->input['base'])->toBe($base);
    expect($source->checkedCommits)->toBe([PROOF_SHA]);
    expect($proof->input['cases'])->toBe([['command' => 'firewall:allow', 'file' => $file,
        'only' => ['first', 'second[one]'], 'reasons' => ['first' => 'changed', 'second[one]' => 'changed']]]);
    expect($proof->input['uncovered'])->toBe([['path' => $file.'#removed', 'reason' => 'gone at tested SHA']]);
    expect($proof->error_code)->toBe('proof.execution_disabled');
});

it('uses the historical selection list and current population for a whole-file selection', function (string $state): void {
    config(['proof.execution' => false]);
    $source = proofSource();
    $source->branches = ['main'];
    $base = str_repeat('a', 40);
    $middle = str_repeat('c', 40);
    $file = 'swamp/proof/firewall/allow.cases.yaml';
    $source->history = [$middle => new ProofChangedFiles($base, [new ProofChangedFile('old/input.py', 'modified')], true), PROOF_SHA => new ProofChangedFiles($middle, [], true)];
    $source->documents[$middle.':swamp/proof/selection.txt'] = "old/input.py  firewall:allow\n";
    $source->documents[PROOF_SHA.':'.$file] = $state === 'empty' ? "command: firewall:allow\ncases: []\n" : "command: firewall:allow\ncases:\n  - {id: newly-added, batch: fresh-fleet}\n";
    if ($state === 'missing') {
        $source->pathSets[PROOF_SHA] = [];
    }
    proofWorld($source);

    $proof = proofAdmitted(base: $base);

    if ($state !== 'current') {
        expect($proof->result['outcome'])->toBe('nothing_to_prove');
        // A file that is gone is gone; a file that is there with no case has no case yet.
        expect($proof->input['uncovered'])->toBe([['path' => $file, 'reason' => $state === 'empty' ? 'none: no case yet' : 'gone at tested SHA']]);
    } else {
        expect($proof->input['cases'])->toBe([['command' => 'firewall:allow', 'file' => $file, 'reasons' => ['*' => 'command code changed']]]);
        expect(app(ProofBatchPlan::class)->read(PROOF_SHA, $proof->input['cases'])[0]['cases'])->toBe(['newly-added']);
    }
})->with(['current', 'empty', 'missing']);

it('refuses a nonancestor range without admitting copies', function (): void {
    $source = proofSource();
    $source->branches = ['main'];
    proofWorld($source);

    $proof = proofAdmitted(base: str_repeat('a', 40));

    expect($proof->error_code)->toBe('proof.base_not_ancestor');
    expect($proof->input['runs'])->toBe([]);
});

it('has nothing to prove for equal ancestor and head', function (): void {
    $source = proofSource();
    $source->branches = ['main'];
    $source->history = [];
    proofWorld($source);

    $proof = proofAdmitted(base: PROOF_SHA);

    expect($proof->result['outcome'])->toBe('nothing_to_prove');
    expect($proof->input['runs'])->toBe([]);
});

it('admits the generated MCP range input through the typed request and queues that base', function (): void {
    Queue::fake();
    $source = proofSource();
    proofWorld($source);
    app(TaskExtensionState::class)->enable();
    $node = Node::query()->where('name', 'swamp')->firstOrFail();
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $node->accessibleNodes()->attach($node);
    $definition = collect(ToolManifest::default()->definitions())->firstWhere('name', 'proof-run');
    expect($definition->inputSchema['properties']['base']['type'])->toBe('string');
    expect($definition->inputSchema['required'])->not->toContain('base');
    $tool = new ApiOperationTool($definition, app(ApiDispatcher::class));
    $caller = Request::create('/mcp', 'POST', server: ['REMOTE_ADDR' => $node->wireguard_ip]);
    $base = str_repeat('a', 40);

    $response = $tool->handle(new Laravel\Mcp\Request(['commit' => PROOF_SHA, 'base' => $base, 'Idempotency-Key' => 'proof-range']), $caller);
    $data = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($data)->toHaveKey('operation.id');
    $proof = Operation::query()->findOrFail($data['operation']['id']);
    expect($proof->input['base'])->toBe($base);
    expect($source->reads)->toBe(0);
    Queue::assertPushed(AdmitProofRun::class, static fn (AdmitProofRun $job): bool => $job->operationId === $proof->id);
});
