<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Data\Nodes\NodeSettingsData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Stores validated settings only after the executor proves the filesystem check. */
final readonly class NodeSettingsOperationCompletion
{
    public function __construct(private NodeSettingsNormalizer $normalizer, private RecordEventBroadcaster $broadcaster) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }

        $node = Node::query()->findOrFail($operation->node_id);
        $settings = $operation->input['settings'] ?? null;
        $normalized = is_array($settings) ? NodeSettingsData::from($settings) : null;
        $fields = ValidatedData::object($operation->input['settings_patch'] ?? []);
        if (! is_bool($fields['apps'] ?? null)
            || (array_key_exists('nested_fleet', $fields) && ! is_bool($fields['nested_fleet']))
            || (array_key_exists('instance_host', $fields) && ! is_bool($fields['instance_host']))) {
            throw new ResourceOperationException('operation.completion_unproven', 'The settings patch has no recorded member ownership.', 409);
        }
        $patch = new NodeSettingsPatch(
            hasApps: $fields['apps'], apps: $normalized?->apps,
            hasNestedFleet: $fields['nested_fleet'] ?? false, nestedFleet: $normalized->nested_fleet ?? false,
            hasInstanceHost: $fields['instance_host'] ?? false, instanceHost: $normalized->instance_host ?? false,
        );
        $node->update(['settings' => $this->normalizer->storedOver($node->settings, $patch->merge($this->normalizer->fromStored($node->settings)))]);
        $this->broadcaster->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray());
    }
}
