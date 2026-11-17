<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;

/** The model route at admission: a container can run T3 before its Node forwarder has a ready router guard. */
final readonly class InstanceModelRoute
{
    /** @return array{ready: true}|array{ready: false, error_code: string, message: string} */
    public function status(): array
    {
        if (! app()->bound(TaskVmModelRoute::class)) {
            return ['ready' => false, 'error_code' => 'operation.kind_unavailable', 'message' => 'The model router is not loaded.'];
        }
        try {
            app(TaskVmModelRoute::class)->assertReady();
        } catch (ResourceOperationException $error) {
            return ['ready' => false, 'error_code' => $error->errorCode, 'message' => $error->getMessage()];
        }

        return ['ready' => true];
    }
}
