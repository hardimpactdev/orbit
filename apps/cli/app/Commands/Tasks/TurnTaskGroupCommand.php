<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\TurnTaskRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class TurnTaskGroupCommand extends TaskCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'task:turn
        {task? : Numeric subtask ID, or the ID of a task with no subtask}
        {--role= : implementer or reviewer}
        {--prompt= : The orchestrator prompt}
        {--thread= : new or the role thread ID. Without it the turn continues the role thread}
        {--check= : Passed handoff check ID for a reviewer}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Run one agent turn on an external task group.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('task', 'Subtask', 'task');
        $role = $this->choiceInput($this->option('role'), 'Role', 'role', ['implementer', 'reviewer']);
        $prompt = $this->textInput($this->option('prompt'), 'Prompt', 'prompt', 100000);
        $thread = $this->optionalText($this->option('thread'), 'Thread', 'thread', 32);
        $check = $this->option('check') === null ? null : $this->idOption('check', 'Check', 'check');
        if (in_array(false, [$groupId, $role, $prompt, $thread, $check], true)) {
            return self::FAILURE;
        }
        $role ??= $this->promptChoice('Role', ['implementer' => 'Implementer', 'reviewer' => 'Reviewer']);
        $prompt ??= $this->promptText('Prompt', 100000, multiline: true);
        if ($thread !== null && preg_match('/\A(?:new|[1-9][0-9]*)\z/D', $thread) !== 1) {
            return $this->renderGatewayFailure('task.thread_invalid', 'Thread must be new or a positive thread ID.');
        }
        if ($role === 'reviewer' && $check === null) {
            $check = $this->idOption('check', 'Check', 'check');
            if ($check === false) {
                return self::FAILURE;
            }
            $check ??= (int) $this->promptText('Passed handoff check ID', 20);
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $connector = $connectors->make($profile);
        $groupId ??= (int) $this->promptText('Subtask or task ID', 20);

        $key = $this->option('idempotency-key');
        $operation = $this->send($connector, new TurnTaskRequest($groupId, $role, $prompt, $thread, $check, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Turn on task group [{$groupId}]", 'Turn')
            : self::FAILURE;
    }

    /** The agent thread and the agent's reply, under the outcome, so one call reads the turn (docs/cli/task/turn.mdx). */
    protected function writeOperationResult(OperationResponse $operation): void
    {
        $agent = $operation->result['agent_id'] ?? null;
        if (! is_int($agent)) {
            return;
        }
        $this->writeHumanMessage('Agent thread: '.$agent);
        $reply = $operation->result['reply'] ?? null;
        is_string($reply) ? $this->writeText('Reply', $reply) : $this->writeHumanMessage('Reply: none');
    }
}
