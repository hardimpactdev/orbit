<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\AutomaticDeployPublicationData;
use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use Illuminate\Support\Facades\DB;

final readonly class PublishAutomaticDeployPolicyAction
{
    public function __construct(
        private RecordEventBroadcaster $broadcaster,
        private AutomaticDeployPolicyWriter $writer,
    ) {}

    public function execute(Instance $instance, AutomaticDeployPublicationData $data): Instance
    {
        $this->writer->assertSwamp($instance);
        $updated = DB::transaction(function () use ($instance, $data): Instance {
            $instance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->writer->assertSwamp($instance);
            $last = InstanceDeployment::query()->where('instance_id', $instance->id)->max('id') ?? 0;
            if ($data->resumedAfter !== $instance->auto_deploy_resumed_after || $data->lastDeploymentId !== $last) {
                throw new ResourceOperationException('instance.auto_deploy_observation_changed', 'Resume or deployment history changed after the policy read.', 409);
            }
            $instance->auto_deploy_state = [...($instance->auto_deploy_state ?? []), ...$data->projection()];
            $instance->save();

            return $instance;
        });
        $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $updated->id, InstanceData::fromModel($updated)->toArray());

        return $updated;
    }
}
