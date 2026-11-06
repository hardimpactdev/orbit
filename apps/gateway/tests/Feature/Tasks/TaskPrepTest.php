<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskPrepProjection;
use App\Domain\Tasks\TaskPrepPrompts;
use App\Domain\Tasks\TaskPrepResults;
use App\Http\Requests\Tasks\StartTaskPrepRequest;
use Illuminate\Support\Facades\Validator;

it('names the three spec files of a command and its pull request', function (): void {
    $prompts = TaskPrepPrompts::for('firewall:deny');

    expect($prompts->chart())->toBe('docs/cli/firewall/deny.mmd')
        ->and($prompts->page())->toBe('docs/cli/firewall/deny.mdx')
        ->and($prompts->cases())->toBe('swamp/proof/firewall/deny.cases.yaml')
        ->and($prompts->title())->toBe('docs(firewall): spec for firewall:deny')
        ->and($prompts->body())->toContain('docs/cli/firewall/deny.mmd');
});

it('puts the command and its files in the draft, review and fix prompts', function (): void {
    $prompts = TaskPrepPrompts::for('firewall:deny');

    expect($prompts->draft())->toContain('`firewall:deny`', 'docs/cli/firewall/deny.mmd', 'ONE commit', '.agents/skills/preparing-commands/SKILL.md')
        ->and($prompts->review())->toContain('`firewall:deny`', 'You edit no file', '`approved`', '`changes_requested`')
        ->and($prompts->fix())->toContain('`firewall:deny`', '--amend --no-edit');
});

it('refuses a command that is not family:command in lower case', function (string $command): void {
    expect(fn () => TaskPrepPrompts::for($command))->toThrow(ResourceOperationException::class, 'family:command');
})->with(['', 'firewall', 'Firewall:deny', 'firewall:', ':deny', 'a:b:c', 'firewall: deny', "firewall:deny\n", '../x:y']);

it('validates the request body to one command', function (): void {
    $rules = (new StartTaskPrepRequest)->rules();

    expect(Validator::make(['command' => 'firewall:deny'], $rules)->passes())->toBeTrue()
        ->and(Validator::make([], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['command' => ['firewall:deny']], $rules)->fails())->toBeTrue();
});

it('reads the provision receipt, a turn result and the pull request wherever Swamp keeps them', function (): void {
    $receipt = ['task_vm_id' => 7, 'guest_node_id' => 9, 'ready' => true, 'enrollment' => ['ready' => true], 'source' => ['commit' => 'a']];
    $turn = ['state' => 'done', 'metrics' => ['tokens' => 12]];
    $pull = ['pull_request_number' => 31, 'pull_request_url' => 'https://github.com/hardimpactdev/orbit/pull/31'];

    expect(TaskPrepResults::receipt(['Check the workspace' => ['stdout' => json_encode($receipt)."\n", 'exitCode' => 0]]))->toBe($receipt)
        ->and(TaskPrepResults::receipt(['x' => ['stdout' => json_encode([...$receipt, 'ready' => false])]]))->toBeNull()
        ->and(TaskPrepResults::turn(['shape_result' => ['result_json' => json_encode($turn), 'successful' => true]]))->toBe($turn)
        ->and(TaskPrepResults::pullRequest(['Read PR result' => ['stdout' => json_encode([...$pull, 'branch' => 'task-5'])]]))->toBe($pull)
        ->and(TaskPrepResults::receipt(null))->toBeNull()
        ->and(TaskPrepResults::turn(['shape_result' => ['result_json' => 'not json']]))->toBeNull()
        ->and(TaskPrepResults::pullRequest(['Read PR result' => ['stdout' => '{"pull_request_url": null}']]))->toBeNull();
});

it('names the thread of each turn from the intent a run recorded, whose reviews are a list', function (): void {
    $turn = static fn (int $thread): array => ['thread_id' => $thread, 'role' => 'implementer'];
    $input = ['draft' => $turn(11), 'fix' => $turn(11), 'reviews' => [$turn(12), $turn(13), $turn(14)]];

    expect(TaskPrepProjection::threads($input))->toBe(['draft' => 11, 'review_1' => 12, 'fix_1' => 11, 'review_2' => 13, 'fix_2' => 11, 'review_3' => 14]);
});

it('limits a review to findings the rules require', function (): void {
    $review = TaskPrepPrompts::for('firewall:deny')->review();

    expect($review)->toContain('at most five findings', 'A matter of taste', 'Run no other proof')
        ->and(TaskPrepPrompts::for('firewall:deny')->fix())->toContain('change nothing else');
});

it('puts the brief in every prompt and the reference text only in the draft', function (): void {
    $prompts = TaskPrepPrompts::for('firewall:allow', "  Match the deny spec.\n", 'REFERENCE-FILE-TEXT');

    expect($prompts->draft())->toContain('Brief of this run:', 'Match the deny spec.', 'REFERENCE-FILE-TEXT')
        ->and($prompts->review())->toContain('Match the deny spec.')->not->toContain('REFERENCE-FILE-TEXT')
        ->and($prompts->fix())->toContain('Match the deny spec.')->not->toContain('REFERENCE-FILE-TEXT')
        ->and(TaskPrepPrompts::for('firewall:allow', ' ', null)->draft())->not->toContain('Brief of this run:');
});

it('accepts the optional brief and reference of a prep request', function (): void {
    $rules = (new StartTaskPrepRequest)->rules();

    expect(Validator::make(['command' => 'firewall:allow', 'brief' => 'x', 'reference' => 'y'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['command' => 'firewall:allow', 'brief' => str_repeat('x', 8001)], $rules)->fails())->toBeTrue();
});

it('puts the runner plan of the cases file in the author, review and fix prompts', function (): void {
    $prompts = TaskPrepPrompts::for('firewall:allow');
    $plan = 'python3 swamp/proof/runner/runner.py plan --file swamp/proof/firewall/allow.cases.yaml';

    expect($prompts->plan())->toBe($plan)
        ->and($prompts->draft())->toContain($plan, 'must exit 0', 'under `uncovered`', 'Do not change the runner')
        ->and($prompts->review())->toContain($plan, 'that is a finding', 'do not approve')
        ->and($prompts->fix())->toContain($plan);
});

it('builds the prompts of the corrections around the review of the owner', function (): void {
    $prompts = TaskPrepPrompts::for('firewall:allow');
    $review = "Finding 4: keyed replay is generic.\nDecision: link operations.md.";

    expect($prompts->corrections($review))->toContain($review, 'ONE new commit', 'Never amend', 'not yours to make', $prompts->plan())
        ->and($prompts->reviewCorrections($review))->toContain($review, 'each correction that the owner asked for', $prompts->plan(), 'do not approve', 'origin/main..HEAD')
        ->and($prompts->reviewCorrections($review))->toContain('Every case id that runs on the default branch', 'A defect copied from the reference text')
        ->and($prompts->corrections($review))->toContain('Keep every case that runs on the default branch', 'python3 -m unittest discover', 'Put no PR or issue number in the files')
        ->and($prompts->fixCorrections())->toContain('amend only your own corrections commit', 'Never amend or rewrite a commit that is already on the branch');
});

it('keeps the unit-test blocker in the first review and gives the corrections review one exception to it', function (): void {
    $prompts = TaskPrepPrompts::for('firewall:allow');
    $first = $prompts->review();
    $corrections = $prompts->reviewCorrections('Finding 1.');

    expect($first)->toContain('the author must name the failing tests as a blocker, which is a finding')
        ->and($first)->not->toContain('One exception')
        ->and($corrections)->toContain('the unit tests must pass. One exception: runner tests that fail only because they pin the old content of the cases file', 'are not a finding of this review', 'still judge the rest')
        ->and($corrections)->not->toContain('as a blocker, which is a finding');
});
