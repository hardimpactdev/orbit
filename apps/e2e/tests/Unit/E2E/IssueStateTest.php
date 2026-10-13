<?php

declare(strict_types=1);

use App\E2E\IssueState;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRecord;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;

function issueStateTopology(string $issue, AttemptId $attempt, ?TopologyRecipe $recipe = null): TopologyRecord
{
    $target = TopologyTarget::for($issue, $attempt, $recipe ?? TopologyRecipe::fromOption(null));
    $images = [];
    foreach ($target->recipe->images() as $image) {
        $images[$image->value] = str_repeat('f', 64);
    }

    return new TopologyRecord(
        TopologyConstructionInputs::create($target, 2, $images),
        new SourceState(str_repeat('b', 40), str_repeat('b', 40)),
        new VerificationReport(true, ['fixture' => verificationProbeFixture()]),
    );
}

function issueStateWorktree(): string
{
    $worktree = temporaryPath('orbit-issue-state-', 4);
    mkdir($worktree, 0700);

    return $worktree;
}

describe('IssueState', function () {
    it('keeps the lease, the topology, and the log under <worktree>/.e2e/', function () {
        $worktree = issueStateWorktree();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $operation = new OperationId(str_repeat('b', 32));
        $recipe = TopologyRecipe::compose(['app-dev']);

        expect($state->hasAttempt())->toBeFalse()
            ->and(fn () => $state->attempt())->toThrow(RuntimeException::class, 'TST-12 has no active attempt.')
            ->and($state->topology())->toBeNull();

        $state->writeAttempt($attempt, $operation, $recipe);
        expect($state->topology())->toBeNull()
            ->and(fn () => $state->requireTopology())->toThrow(RuntimeException::class, 'has an active lease but no topology record');

        $state->writeTopology(issueStateTopology('TST-12', $attempt, $recipe));
        $state->log("acquire attempt={$attempt->value}\nsecond line");

        expect($state->root())->toBe($worktree.'/.e2e')
            ->and($state->attemptId()->value)->toBe($attempt->value)
            ->and($state->operationId()->value)->toBe($operation->value)
            ->and($state->attempt())->not->toHaveKey('purpose')
            ->and($state->recipe()->nodeKeys())->toBe(['gateway', 'app-dev', 'operator'])
            ->and($state->requireTopology()->attempt->value)->toBe($attempt->value)
            ->and(file_get_contents($worktree.'/.e2e/log'))
            ->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z acquire attempt=a{32} second line\n\z/')
            ->and(array_values(array_diff(scandir($worktree.'/.e2e'), ['.', '..'])))
            ->toEqualCanonicalizing(['attempt.json', 'topology.json', 'log']);

        $state->forgetAttempt();
        expect($state->hasAttempt())->toBeFalse()
            ->and(is_file($worktree.'/.e2e/log'))->toBeTrue();
    });

    it('rejects a lease or record that names another issue, attempt, or Node set', function () {
        $worktree = issueStateWorktree();
        $attempt = new AttemptId(str_repeat('a', 32));
        $state = IssueState::forWorktree('TST-12', $worktree);
        $state->writeAttempt($attempt, new OperationId(str_repeat('b', 32)), TopologyRecipe::compose(['app-dev']));
        $state->writeTopology(issueStateTopology('TST-12', new AttemptId(str_repeat('c', 32)), TopologyRecipe::compose(['app-dev'])));

        expect(fn () => IssueState::forWorktree('TST-13', $worktree)->attempt())
            ->toThrow(RuntimeException::class, 'lease is invalid')
            ->and(fn () => IssueState::forWorktree('TST-12', $worktree)->requireTopology())
            ->toThrow(RuntimeException::class, 'name different attempts');

        $state->writeTopology(issueStateTopology('TST-12', $attempt));
        expect(fn () => $state->requireTopology())
            ->toThrow(RuntimeException::class, 'declare different Nodes');
    });

    it('refuses a lease from an older harness', function () {
        $worktree = issueStateWorktree();
        mkdir($worktree.'/.e2e', 0700);
        file_put_contents($worktree.'/.e2e/attempt.json', json_encode([
            'issue' => 'TST-12',
            'attempt_id' => str_repeat('a', 32),
            'purpose' => 'discovery',
            'operation_id' => str_repeat('b', 32),
            'acquired_at' => '2026-10-01T00:00:00Z',
            'extension' => null,
            'snapshot_replacement' => false,
        ], JSON_THROW_ON_ERROR));

        expect(fn () => IssueState::forWorktree('TST-12', $worktree)->attempt())
            ->toThrow(RuntimeException::class, 'from an older harness; release it with the harness that created it');
    });

    it('writes a fresh readable mount token and reports its hash', function () {
        $worktree = issueStateWorktree();
        $state = IssueState::forWorktree('TST-12', $worktree);

        expect($state->mountTokenHash())->toBeNull();

        $first = $state->writeMountToken();
        $token = (string) file_get_contents($worktree.'/.e2e/mount-token');

        expect($first)->toBe(hash('sha256', $token))
            ->and($state->mountTokenHash())->toBe($first)
            ->and(fileperms($worktree.'/.e2e/mount-token') & 0o777)->toBe(0o644)
            ->and($state->writeMountToken())->not->toBe($first);
    });
});
