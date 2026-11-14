<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Environment\ContainerConnectionEnvironment;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class UpdateInstanceEnvironmentAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
    ) {}

    public function execute(
        Instance $instance,
        string $key,
        #[\SensitiveParameter]
        string $value,
    ): InstanceEnvironmentResult {
        return $this->operations->run([$instance->id], function () use (
            $instance,
            $key,
            $value,
        ): InstanceEnvironmentResult {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: false);

            if (
                $context->laravel
                && $key === 'APP_URL'
                && $value !== 'https://{{instance.domain}}'
            ) {
                throw new ResourceOperationException(
                    errorCode: 'env.configuration_invalid',
                    message: 'The complete Instance environment configuration is invalid.',
                    details: [
                        'key' => 'APP_URL',
                        'rule' => InstanceEnvironmentValidator::RuleLaravelAppUrl,
                    ],
                );
            }

            if (! $instance->runtime()->exists()) {
                return $this->store->update($context, $key, $value);
            }

            // A container's `.env` is never rendered again: the next env:sync writes this key once (docs/cli/env/sync.mdx#on-a-container-instance).
            return DB::transaction(function () use ($instance, $context, $key, $value): InstanceEnvironmentResult {
                $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
                // A running sync clears the keys it admitted when it ends; a value stored meanwhile would never be written.
                if (Operation::query()->where('kind', ContainerConnectionEnvironment::Kind)->where('target_type', 'instance')
                    ->where('target_id', $locked->id)->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                    throw new ResourceOperationException('instance.lifecycle_busy', 'An env:sync of this Instance is running; run env:update after it ends.', 409);
                }
                $result = $this->store->update($context, $key, $value);
                $pending = array_values(array_unique([...($locked->environment_pending_keys ?? []), $key]));
                sort($pending, SORT_STRING);
                $locked->forceFill(['environment_pending_keys' => $pending])->saveOrFail();

                return $result;
            });
        });
    }
}
