<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeData;
use App\Data\Nodes\NodeSettingsData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class UpdateNodeSettingsAction
{
    public function __construct(
        private NodeSettingsNormalizer $normalizer,
        private ConfiguredStoragePathValidator $validator,
        private ManagedUserAccountResolver $accounts,
        private StartOperationAction $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function start(Node $node, NodeSettingsPatch $patch, ?Node $caller = null, ?string $requestId = null): Node|Operation
    {
        return DB::transaction(function () use ($node, $patch, $caller, $requestId): Node|Operation {
            $node->refresh();
            $merged = $patch->merge($this->normalizer->fromStored($node->settings));
            $normalized = $this->normalizer->normalize($merged);
            $this->validateHostBridge($node, $normalized);
            $this->validator->validateGrammar($normalized);
            $create = $this->hasActiveAppDevRole($node);
            if (! $patch->hasApps || (! $create && $normalized?->appsPath() === null)) {
                $this->persist($node, $normalized);
                ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                    RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray(),
                );

                return $node;
            }

            $account = $this->accounts->resolve($node);
            $root = $this->validator->validateEffective($normalized, $node, $account);

            return $this->operations->execute('node.settings', 'node', $node->id, $node->id, [
                'node_id' => $node->id, 'caller_node_id' => $caller?->id,
                'root' => $root->value, 'user' => $account->user, 'group' => $account->group,
                'create_missing' => $create, 'settings' => $this->normalizer->stored($normalized),
            ], 'operator', $requestId);
        });
    }

    public function execute(Node $node, NodeSettingsPatch $patch): Node
    {
        $result = $this->start($node, $patch);
        if ($result instanceof Operation) {
            $result = app(AwaitOperation::class)->execute($result);
            if ($result->status !== OperationStatus::Succeeded) {
                throw new ResourceOperationException($result->error_code ?? ($result->status === OperationStatus::Cancelled ? 'operation.cancelled' : 'operation.failed'),
                    'The Node Operation did not succeed.', 409, details: ['operation_id' => $result->id]);
            }
        }

        return $node->refresh();
    }

    public function persistDuringProvisioning(Node $node, ?NodeSettingsData $settings): void
    {
        $this->execute($node, new NodeSettingsPatch(true, $settings?->apps, true, $settings?->task_vms));
    }

    private function validateHostBridge(Node $node, ?NodeSettingsData $settings): void
    {
        if ($settings?->task_vms === null) {
            return;
        }
        $bridge = Ipv4Subnet::from($settings->task_vms->cidr);
        foreach (Node::query()->where('id', '!=', $node->id)->get() as $other) {
            $stored = $other->settings;
            $host = is_array($stored) ? $stored['task_vms'] ?? null : null;
            $cidr = is_array($host) ? $host['cidr'] ?? null : null;
            if (is_string($cidr)) {
                $subnet = Ipv4Subnet::from($cidr);
                if ($bridge->contains($subnet->networkAddress()) || $subnet->contains($bridge->networkAddress())) {
                    throw new ResourceOperationException('node.settings_invalid', 'The task_vms bridge overlaps another host.');
                }
            }
        }
    }

    private function persist(Node $node, ?NodeSettingsData $normalized): void
    {
        $node->settings = $this->normalizer->storedOver($node->settings, $normalized);
        $node->save();
    }

    private function hasActiveAppDevRole(Node $node): bool
    {
        return $node
            ->roles()
            ->where('role', RoleName::AppDev->value)
            ->where('status', LifecycleStatus::Active->value)
            ->exists();
    }
}
