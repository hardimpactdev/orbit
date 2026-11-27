<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptRepositoryOutcomes;
use App\Domain\Tools\AptRepositoryStatus;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * Removes an apt repository's source, keyring and preferences from its Node, and then its record. Refused while any Tool references it:
 * removing a Tool never removes its repository (docs/reference/tools.md#apt-repositories).
 */
final readonly class RemoveAptRepositoryAction
{
    public function __construct(private StartOperationAction $start, private StartToolOperationAction $tools, private ToolConvergeIntent $intent,
        private SwampSshExecutionInput $ssh, private OperationIdempotency $idempotency) {}

    public function execute(int $repositoryId, string $requestId): Operation
    {
        if (($existing = $this->idempotency->replay('tool.apt_repository_remove')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($repositoryId, $requestId): Operation {
            $record = AptRepository::query()->lockForUpdate()->findOrFail($repositoryId);
            $node = Node::query()->lockForUpdate()->findOrFail($record->node_id);
            $this->tools->manager($node, ToolManagerName::Apt->value);
            if ($record->tools()->exists()) {
                throw new ResourceOperationException('tool.repository_in_use', 'Tools reference this apt repository: remove them first.', 409);
            }
            $this->intent->assertIdle($node->id, [ToolManagerName::Apt->scope()->value]);
            $previous = ['status' => $record->status->value, 'error_code' => $record->error_code, 'definition' => $record->definition, 'operation_id' => $record->operation_id];
            $record->update(['status' => AptRepositoryStatus::Removing, 'error_code' => null]);
            $intent = ['node_id' => $node->id, 'user' => $node->user, 'tools' => [], 'repositories' => [], 'tools_seconds' => 500,
                'removed_repositories' => [$record->name], 'ssh' => $this->ssh->forNode($node)];

            $operation = $this->start->execute('tool.apt_repository_remove', 'node_tools', $node->id, $node->id,
                ['manager' => ToolManagerName::Apt->value, 'repository_id' => $record->id, 'name' => $record->name, 'previous' => $previous, 'intent' => $intent],
                'operator', $requestId, lockKey: ToolManagerName::Apt->scope()->operationLockKey($node->id));
            AptRepositoryOutcomes::claim([$record->id], $operation->id);

            return $operation;
        });
    }
}
