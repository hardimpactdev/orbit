<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskRubricItem;
use App\Domain\Tasks\TaskRubricReminder;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnMode;

it('tells a finished implementer how to end its turn with the turn command', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Implementer, [
        new TaskRubricItem('check_invoked', false, 'composer check was not found in the recent tool output. Run composer check.'),
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ]);

    expect($reminder)->toBe('Orbit could not confirm the brief is complete. composer check was not found in the recent tool output. Run composer check. No turn receipt was found. '.TaskTurnInstructions::implementer());
});

it('tells a reviewer how to end its turn with the turn command', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ]);

    expect($reminder)->toBe('Orbit could not confirm the review is complete. No turn receipt was found. '.TaskTurnInstructions::reviewer());
});

it('does not assume a Project task check when none was supplied', function (): void {
    expect(TaskTurnInstructions::implementer())
        ->not->toContain('composer check')
        ->and(TaskRubricReminder::compose(TaskThreadRole::Implementer, []))
        ->not->toContain('composer check');
});

it('tells the reviewer to leave the workspace tree unchanged but lets it verify outside the workspace', function (): void {
    expect(TaskTurnInstructions::reviewer())
        ->toContain('Leave the workspace tree as you found it: Orbit checks that no tracked or untracked workspace file changed when your turn ends.')
        ->toContain('You may run commands, use sudo, and create disposable files outside the workspace, such as under /tmp, to verify behaviour.')
        ->toContain('Request changes from the implementer instead of editing the code.')
        ->not->toContain('read-only')
        ->toContain('--outcome=approved')
        ->toContain('--outcome=changes_requested')
        ->not->toContain('This is a consult')
        ->not->toContain('--outcome=answered')
        ->not->toContain('You may create');
});

it('keeps cause-required verdicts in a reminder after an operator resolution', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [], mode: new TaskTurnMode(causeRequired: true));

    expect($reminder)->toContain(
        '--outcome=approved --summary="What you checked" --cause=CAUSE',
        '--outcome=changes_requested --summary="The findings the implementer must address" --cause=CAUSE',
    )->not->toContain('--outcome=answered');
});

it('uses consult and relay instructions only for those turns', function (): void {
    $consult = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ], mode: new TaskTurnMode(consult: true));
    $relay = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ], mode: new TaskTurnMode(relay: true));

    expect($consult)->toBe('Orbit could not confirm the review is complete. No turn receipt was found. '.TaskTurnInstructions::consult())
        ->and($consult)->toContain('This is a consult, not a review.')
        ->and($consult)->toContain('--outcome=answered')
        ->and($consult)->not->toContain('--outcome=approved')
        ->and($relay)->toBe('Orbit could not confirm the review is complete. No turn receipt was found. '.TaskTurnInstructions::relay())
        ->and($relay)->toContain("This is a relay of the operator's direction, not a review.")
        ->and($relay)->toContain('--outcome=answered')
        ->and($relay)->not->toContain('--outcome=approved');
});

it('tells the implementer to pass the Project task check, or only to finish the brief without one', function (): void {
    expect(TaskTurnInstructions::implementer([], 'vp run check'))
        ->toContain('When the brief is complete and vp run check passes, end your turn')
        ->not->toContain('access you do not have')
        ->and(TaskTurnInstructions::implementer([], null))
        ->toContain('When the brief is complete, end your turn')
        ->not->toContain('composer check')
        ->not->toContain('again at handoff');
});
