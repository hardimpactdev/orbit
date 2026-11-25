<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolDefinition;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Models\ConnServerNode;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;

final readonly class ConnServerToolMembership
{
    public function __construct(private StartOperationAction $operations) {}

    public function record(string $verb, int $nodeId, ?Tool $tool, ToolDefinition $definition, string $requestId): Operation
    {
        $server = ConnServerNode::query()->where('node_id', $nodeId)->firstOrFail();
        $unchanged = $verb !== 'remove' && $tool !== null && ! $server->tool_withdrawn
            && $definition->sameAs(new ToolDefinition($tool->desired_version, $tool->version_constraint, null));
        if ($verb === 'install' && $tool !== null && ! $unchanged) {
            throw new ResourceOperationException('tool.package_already_installed', 'Update the existing Conn Server membership instead.', 409);
        }

        return $this->operations->record('tool.'.$verb, 'node_tools', $nodeId, $nodeId,
            ['manager' => ToolManagerName::ConnServer->value, 'package' => ConnServerToolManager::Package, 'desired_version' => $definition->version, 'intent_only' => true, 'tool_id' => $tool?->id],
            ['outcome' => $unchanged ? 'unchanged' : 'applied', 'installed_version' => $tool?->installed_version, 'execution' => 'membership_only'], 'operator', $requestId,
            ToolManagerName::ConnServer->operationLockKey($nodeId), static function () use ($verb, $nodeId, $server, $tool, $definition, $unchanged): array {
                if ($unchanged) {
                    return ['tool_id' => $tool?->id];
                }
                if ($verb === 'remove') {
                    $server->update(['tool_withdrawn' => true]);
                    $tool?->delete();

                    return ['tool_id' => $tool?->id];
                }
                $record = ToolManagerRecord::query()->firstOrCreate(['node_id' => $nodeId, 'name' => ToolManagerName::ConnServer->value], ['status' => LifecycleStatus::Active]);
                $member = $tool ?? new Tool(['node_id' => $nodeId, 'tool_manager_id' => $record->id, 'package' => ConnServerToolManager::Package]);
                $member->fill(['status' => ToolStatus::Installed, 'desired_version' => $definition->version, 'version_constraint' => $definition->constraint])->save();
                $server->update(['tool_withdrawn' => false]);

                return ['tool_id' => $member->id];
            });
    }
}
