<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\CheckTaskRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class CheckTaskGroupCommand extends TaskCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'task:check
        {task? : Numeric Task ID}
        {--phase= : baseline or handoff}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Run a baseline or handoff check in an external task workspace.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('task', 'Subtask', 'task');
        if ($groupId === false) {
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
        $groupId ??= (int) $this->promptText('Subtask ID', 20);

        $phase ??= $this->promptChoice('Phase', ['baseline' => 'Baseline', 'handoff' => 'Handoff']);
        $key = $this->option('idempotency-key');
        $operation = $this->send($connector, new CheckTaskRequest($groupId, $phase, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Check task [{$groupId}]", 'Check')
            : self::FAILURE;
    }
}
