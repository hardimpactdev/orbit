<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptRepositories;
use App\Domain\Tools\AptRepositoryOutcomes;
use App\Domain\Tools\AptRepositoryStatus;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolPolicy;
use App\Http\Requests\Tools\AptRepositoryRules;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a signed apt repository on a Node: the run verifies the key fingerprint, holds the repository's preferences to the owned
 * Tools that reference it, then writes the keyring and source and reads its package index (docs/reference/tools.md#apt-repositories).
 * Adding a name again publishes it again: the one recovery for a failed or unconfirmed repository.
 */
final readonly class AddAptRepositoryAction
{
    public function __construct(private StartOperationAction $start, private StartToolOperationAction $tools, private ToolConvergeIntent $intent,
        private SwampSshExecutionInput $ssh, private OperationIdempotency $idempotency) {}

    /** @param array<string, mixed> $definition without its name */
    public function execute(int $nodeId, string $name, array $definition, string $requestId): Operation
    {
        if (($existing = $this->idempotency->replay('tool.apt_repository_add')) !== null) {
            return $existing;
        }
        $definition = ['name' => $name, ...$definition];

        return DB::transaction(function () use ($nodeId, $name, $definition, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
            $this->tools->manager($node, ToolManagerName::Apt->value);
            new AptRepositoryRules()->validate($definition, ToolManagerName::Apt->value);
            $active = app(ToolPolicy::class)->active();
            if ($active->mode === 'file') {
                $catalog = $active->repository($name) ?? throw new ResourceOperationException('tool.not_in_catalog', 'The apt repository is not in the active Tool catalog.', 409);
                if (! AptRepositories::same($catalog, $definition)) {
                    throw new ResourceOperationException('tool.file_override', 'File mode takes the repository definition only from its catalog.', 409);
                }
            }
            $this->intent->assertIdle($node->id, [ToolManagerName::Apt->scope()->value]);
            AptRepositories::assertDistinct($node->id, $definition);
            $record = AptRepository::query()->where('node_id', $node->id)->where('name', $name)->lockForUpdate()->first();
            if ($record !== null && ! AptRepositories::same($record->definition, $definition) && $record->tools()->exists()) {
                throw new ResourceOperationException('tool.repository_conflict', 'Tools reference this repository: remove them before changing its definition.', 409);
            }
            $previous = $record === null ? null : ['status' => $record->status->value, 'error_code' => $record->error_code, 'definition' => $record->definition,
                'operation_id' => $record->operation_id];
            $record ??= new AptRepository(['node_id' => $node->id, 'name' => $name]);
            $record->fill(['definition' => $definition, 'status' => AptRepositoryStatus::Adding, 'error_code' => null])->save();
            // The preferences the run writes before the source: the repository's owned members keep their pin, every other package of its
            // origins stays at priority 1.
            $owned = Tool::query()->where('apt_repository_id', $record->id)->where('owned', true)->with('manager')->get()
                ->reject->withdrawn()->pluck('package')->sort()->values()->all();
            $intent = ['node_id' => $node->id, 'user' => $node->user, 'tools' => [], 'tools_seconds' => 500, 'ssh' => $this->ssh->forNode($node),
                'repositories' => [['id' => $record->id, 'repository' => $definition, 'repository_packages' => $owned]], 'removed_repositories' => []];

            $operation = $this->start->execute('tool.apt_repository_add', 'node_tools', $node->id, $node->id,
                ['manager' => ToolManagerName::Apt->value, 'repository_id' => $record->id, 'name' => $name, 'previous' => $previous, 'intent' => $intent],
                'operator', $requestId, lockKey: ToolManagerName::Apt->scope()->operationLockKey($node->id));
            AptRepositoryOutcomes::claim([$record->id], $operation->id);

            return $operation;
        });
    }
}
