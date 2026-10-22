<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\CheckTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class CheckTaskGroupCommand extends TaskCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tasks:check
        {group? : Numeric task group ID}
        {--subtask= : Numeric subtask ID}
        {--phase= : baseline or handoff}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Run a baseline or handoff check in an external task workspace.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');
        if ($groupId === false) {
            return self::FAILURE;
        }
        $subtask = $this->idOption('subtask', 'Subtask', 'subtask');
        if ($subtask === false) {
            return self::FAILURE;
        }
        $phase = $this->choiceInput($this->option('phase'), 'Phase', 'phase', ['baseline', 'handoff']);
        if ($phase === false) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $connector = $connectors->make($profile);
        $groupId ??= $this->selectGroup($connector, ['running', 'reviewing']);
        if ($groupId === null) {
            return self::FAILURE;
        }
        if ($subtask === null) {
            $group = $this->loadGroup($connector, $groupId);
            if ($group === null) {
                return self::FAILURE;
            }
            $subtask = $this->selectSubtask($group, ['todo', 'running', 'reviewing']);
        }
        $phase ??= $this->promptChoice('Phase', ['baseline' => 'Baseline', 'handoff' => 'Handoff']);
        $key = $this->option('idempotency-key');
        $operation = $this->send($connector, new CheckTaskGroupRequest($groupId, $subtask, $phase, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Check task group [{$groupId}]", 'Check')
            : self::FAILURE;
    }
}
