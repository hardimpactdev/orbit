<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRequestFingerprint;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Orbit\Extensions\Conn\Data\ConnLaunchRequestData;
use Orbit\Extensions\Conn\Data\ConnLaunchSnapshot;
use Orbit\Extensions\Conn\Domain\ConnInstanceNames;
use Orbit\Extensions\Conn\Domain\ConnLaunchAccess;
use Orbit\Extensions\Conn\Domain\ConnThreadProjects;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnLaunchIntent;
use Orbit\Extensions\Conn\Models\ConnProfile;

final readonly class AdmitConnLaunchAction
{
    public function __construct(
        private ConnLaunchAccess $access,
        private ConnThreadProjects $projects,
        private ConnInstanceNames $names,
        private OperationRequestFingerprint $fingerprints,
        private OperationIdempotency $idempotency,
        private OperationStore $store,
        private Encrypter $encrypter,
        private ConnLaunchSnapshot $snapshot,
    ) {}

    /** @return array<string, mixed> */
    public function execute(Node $caller, ConnLaunchRequestData $input, string $requestId): array
    {
        return DB::transaction(function () use ($caller, $input, $requestId): array {
            $caller = $this->access->lockActivePeer($caller);
            $profileId = $this->access->profileId($caller, lock: true);
            if ($caller->id !== $input->expectedNodeId || $profileId !== $input->expectedProfileId) {
                self::scopeChanged();
            }

            $this->access->assertCollectionAccess($caller);

            $kind = OperationKinds::ConnLaunch;
            $key = 'node:'.$caller->id.':'.$input->launchId;
            $existing = Operation::query()->where('kind', $kind)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->target_id !== $profileId) {
                    self::scopeChanged();
                }
                $this->assertTargetAccess($caller, $existing->node_id);
            }

            $fingerprint = hash_hmac('sha256', 'conn.launch:v1.1:'.$this->fingerprints->make($caller, 'conn:launch:create', [],
                $input->privateRequest(), new StartsOperation($kind, bindCaller: true)), $this->encrypter->getKey());

            $operation = $this->idempotency->under($kind, $key, $fingerprint, function () use ($caller, $input, $profileId, $requestId, $existing, $kind): Operation {
                if ($existing !== null) {
                    return $this->idempotency->replay($kind) ?? throw new \LogicException('The locked launch is not replayable.');
                }

                $this->assertWorkspace($profileId, $input->workspaceId);
                $project = Project::query()->findOrFail($input->projectId);
                $instance = $this->target($caller, $project, $input);
                $new = $input->targetKind === 'new';

                $operation = $this->store->create($kind, ConnLaunchAccess::Target, $profileId,
                    $instance->node_id, [
                        'launch_id' => $input->launchId,
                        'project_id' => $project->id,
                        'workspace_id' => $input->workspaceId,
                        'caller_node_id' => $caller->id,
                        'source' => $input->source,
                        'target_kind' => $input->targetKind,
                        ...($new ? ['default_instance_id' => $instance->id, 'target_name' => $input->name] : ['instance_id' => $instance->id]),
                    ], 'node:'.$caller->id, $requestId);
                ConnLaunchIntent::query()->create(['operation_id' => $operation->id, 'request' => $input->privateRequest()]);
                $this->store->change($operation, ['state' => ['launch_phase' => 'preparing']]);

                return $operation;
            });

            return $this->snapshot->project($operation);
        });
    }

    private function target(Node $caller, Project $project, ConnLaunchRequestData $input): Instance
    {
        if ($input->targetKind === 'new') {
            $default = $this->projects->default($caller, $project);
            $name = $input->name ?? throw new \LogicException('A new target requires a name.');
            if ($this->names->isDefault($project, $name)) {
                throw ValidationException::withMessages(['target.name' => ['A launch needs a clone name, not the default Instance.']]);
            }
            if ($this->names->occupied($project, $name) !== null) {
                throw new ResourceOperationException('instance.name_taken', 'That Instance name is already in use in this Project.', 409);
            }

            return $default;
        }

        $instance = Instance::query()->where('project_id', $project->id)->findOrFail($input->instanceId);
        $this->assertTargetAccess($caller, $instance->node_id);
        if ($instance->status === InstanceState::Removing || ! ConnEnvironment::query()->where('instance_id', $instance->id)
            ->where('node_id', $instance->node_id)->exists()) {
            throw new ResourceOperationException('conn.launch_target_unavailable', 'The Instance has no available registered Conn environment.', 409);
        }

        return $instance;
    }

    private function assertWorkspace(int $profileId, ?string $workspaceId): void
    {
        if ($workspaceId === null) {
            return;
        }
        $workspaces = ConnProfile::query()->findOrFail($profileId)->settings['workspaces'] ?? [];
        if (! is_array($workspaces) || ! array_any($workspaces, static fn (mixed $workspace): bool => is_array($workspace) && ($workspace['id'] ?? null) === $workspaceId)) {
            throw ValidationException::withMessages(['workspace_id' => ['The workspace does not belong to the current Conn profile.']]);
        }
    }

    private function assertTargetAccess(Node $caller, ?int $nodeId): void
    {
        if (! $this->access->allowsTarget($caller, $nodeId)) {
            throw new ResourceOperationException('node_access.required', 'Node access is required.', 403);
        }
    }

    private static function scopeChanged(): never
    {
        throw new ResourceOperationException('conn.launch_scope_changed', 'The device or its Conn profile has changed.', 409);
    }
}
