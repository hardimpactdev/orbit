<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeData;
use App\Data\Nodes\NodeSettingsData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class UpdateNodeSettingsAction
{
    public function __construct(
        private NodeSettingsNormalizer $normalizer,
        private ConfiguredStoragePathValidator $validator,
        private ManagedUserAccountResolver $accounts,
        private NodeStorageRootPreparer $preparer,
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
            $create = $this->hasActiveAppDevRole($node);
            if (! $patch->hasApps || (! $create && $normalized?->appsPath() === null)) {
                $this->persist($node, $normalized);
                ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                    RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray(),
                );

                return $node;
            }

            $storage = $this->validator->directIntent($normalized, $node, $create);
            if ($node->platform !== 'linux') {
                throw new ResourceOperationException('node.settings_platform_unsupported', 'Apps-root actions require a Linux Node.');
            }
            if (! app(NodeWorkerRollout::class)->allows($node)) {
                throw new ResourceOperationException('swamp.node_worker_not_ready', 'Apps-root actions require a Node in the worker rollout.');
            }

            return $this->operations->execute('node.settings', 'node', $node->id, $node->id, [
                'agent_pin' => app(AgentDelivery::class)->pin($node),
                'node_id' => $node->id, 'caller_node_id' => $caller?->id,
                ...$storage, 'settings' => $this->normalizer->stored($normalized),
                'settings_patch' => ['apps' => $patch->hasApps, 'task_vms' => $patch->hasTaskVms],
            ], 'operator', $requestId, expectedWriter: 'direct');
        });
    }

    public function persistDuringProvisioning(Node $node, ?NodeSettingsData $settings): void
    {
        $normalized = $this->normalizer->normalize($settings);
        $this->validateHostBridge($node, $normalized);
        $this->apply($node, $normalized);
    }

    /** @return array{settings:array<string,mixed>|null,storage:array{root:string,user:string,group:string,create_missing:bool}|null} */
    public function enrollmentIntent(Node $node, ?NodeSettingsData $settings): array
    {
        $normalized = $this->normalizer->normalize($settings);
        $this->validateHostBridge($node, $normalized);
        $this->validator->validateGrammar($normalized);
        $create = $this->hasActiveAppDevRole($node);
        $storage = null;
        if ($create || $normalized?->appsPath() !== null) {
            $account = $this->accounts->resolve($node);
            $root = $this->validator->validateEffective($normalized, $node, $account);
            $storage = ['root' => $root->value, 'user' => $account->user, 'group' => $account->group, 'create_missing' => $create];
        }

        return ['settings' => $this->normalizer->stored($normalized), 'storage' => $storage];
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

    private function apply(Node $node, ?NodeSettingsData $normalized): void
    {
        $this->validator->validateGrammar($normalized);

        if ($this->hasActiveAppDevRole($node)) {
            $this->prepareEffectiveRoots($node, $normalized);
            $this->persist($node, $normalized);

            return;
        }

        if ($normalized instanceof NodeSettingsData) {
            $this->inspectConfiguredRoots($node, $normalized);
        }

        $this->persist($node, $normalized);
    }

    private function prepareEffectiveRoots(Node $node, ?NodeSettingsData $normalized): void
    {
        $account = $this->accounts->resolve($node);
        $roots = $this->validator->validateEffective($normalized, $node, $account);
        $this->preparer->prepare($node, $account, $roots);
    }

    private function inspectConfiguredRoots(Node $node, NodeSettingsData $settings): void
    {
        $account = $this->accounts->resolve($node);
        $this->validator->validateEffective($settings, $node, $account);
        $this->inspectExplicitRoots($node, $account, $settings);
    }

    private function persist(Node $node, ?NodeSettingsData $normalized): void
    {
        $node->settings = $this->normalizer->storedOver($node->settings, $normalized);
        $node->save();
    }

    private function inspectExplicitRoots(Node $node, ManagedUserAccount $account, NodeSettingsData $settings): void
    {
        $path = $settings->appsPath();

        if (is_string($path) && $path !== '') {
            $this->preparer->inspect($node, $account, StoragePath::parse($path));
        }
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
