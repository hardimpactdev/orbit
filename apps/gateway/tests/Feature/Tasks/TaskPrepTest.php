<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
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
