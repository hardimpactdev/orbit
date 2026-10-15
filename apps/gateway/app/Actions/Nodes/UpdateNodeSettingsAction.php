<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

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
use App\Models\Node;

final readonly class UpdateNodeSettingsAction
{
    public function __construct(
        private NodeSettingsNormalizer $normalizer,
        private ConfiguredStoragePathValidator $validator,
        private ManagedUserAccountResolver $accounts,
        private NodeStorageRootPreparer $preparer,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Node $node, NodeSettingsPatch $patch): Node
    {
        $merged = $patch->merge($this->normalizer->fromStored($node->settings));
        $normalized = $this->normalizer->normalize($merged);
        $this->validateHostBridge($node, $normalized);
        if ($patch->hasApps) {
            $this->apply($node, $normalized);
        } else {
            $this->persist($node, $normalized);
        }

        $result = $node->refresh();

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::NodeUpdated,
            $result->id,
            NodeData::fromModel($result)->toArray(),
        );

        return $result;
    }

    public function persistDuringProvisioning(Node $node, ?NodeSettingsData $settings): void
    {
        $normalized = $this->normalizer->normalize($settings);
        $this->validateHostBridge($node, $normalized);
        $this->apply($node, $normalized);
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
