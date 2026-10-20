<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Data\Instances\InstanceRemovalData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Http\Responses\ApiErrorResponse;
use App\Http\Responses\RendersApiError;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Models\InstanceRemoval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class InstanceRemovalException extends RuntimeException implements RendersApiError
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly InstanceRemoval $removal,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Instance removal was accepted but remains incomplete.', 0, $previous);
    }

    #[\Override]
    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The API error envelope, which the exception handler returns for this exception. */
    #[\Override]
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', $this->apiErrorCode());

        // The Node output of the step that failed, such as a source finalization that refused with `cross-device:`.
        for ($cause = $this->getPrevious(); $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof RuntimeConvergenceException && $cause->result instanceof CommandResult) {
                $request->attributes->set('orbit.command_result', $cause->result);

                break;
            }
        }

        $removal = InstanceRemovalData::fromModel($this->removal)->toArray();
        $request->attributes->set('orbit.instance_removal', $removal);

        return ApiErrorResponse::make($request, $this->apiErrorCode(), $this->getMessage(), ['removal' => $removal], GatewayExceptionStatus::for($this));
    }
}
