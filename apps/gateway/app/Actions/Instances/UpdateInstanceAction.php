<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Deployment\SharedPaths;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Models\Instance;

final readonly class UpdateInstanceAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $resolver,
        private InstanceEnvironmentOperationLock $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    /** @param list<string>|null $sharedPaths */
    public function execute(Instance $instance, ?string $branch, ?array $sharedPaths = null, bool $sharedPathsProvided = false): Instance
    {
        $result = $this->operations->run([$instance->id], function () use ($instance, $branch, $sharedPaths, $sharedPathsProvided): Instance {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);
            if ($branch !== null) {
                $locked->deployment_branch = $branch;
            }
            if ($sharedPathsProvided) {
                $locked->shared_paths = $sharedPaths === null ? null : SharedPaths::validate($sharedPaths);
            }
            $locked->save();

            return $locked->refresh();
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::InstanceUpdated,
            $result->id,
            InstanceData::fromModel($result)->toArray(),
        );

        return $result;
    }
}
