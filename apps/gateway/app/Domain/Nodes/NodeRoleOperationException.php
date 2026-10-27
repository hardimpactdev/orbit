<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Http\Responses\ApiErrorResponse;
use App\Http\Responses\RendersApiError;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Infrastructure\Processes\CommandResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class NodeRoleOperationException extends RuntimeException implements RendersApiError
{
    public readonly ?CommandResult $result;

    /** The failed step's own code, or `node.lock_lost` when the operation lost one of its Node locks. */
    public readonly string $underlyingErrorCode;

    public function __construct(
        public readonly string $step,
        public readonly string $errorCode,
        string $underlyingErrorCode,
        string $message,
        ?CommandResult $result = null,
        ?Throwable $previous = null,
        /** HTTP 502, or the status of the Node convergence refusal it wraps. */
        public readonly int $status = 502,
    ) {
        $this->underlyingErrorCode = NodeLockLoss::in($previous) ? NodeLockLoss::ErrorCode : $underlyingErrorCode;
        $this->result = $result === null
            ? null
            : new CommandResult(
                exitCode: $result->exitCode,
                stdout: '',
                stderr: '',
                durationMs: $result->durationMs,
                truncated: $result->truncated,
            );

        parent::__construct($message, previous: $previous);
    }

    /**
     * The specific error code of the first role operation failure in the exception chain, as API error
     * details: `error_code`, such as `node_role.node_busy` or `metrics.image_pull_failed`. The top-level
     * code stays the operation's (`node_role.convergence_failed`, `node_role.remove_failed`).
     *
     * @return array{error_code?: string}
     */
    public static function detailsIn(?Throwable $exception): array
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof self) {
                return ['error_code' => $current->underlyingErrorCode];
            }
        }

        return [];
    }

    /** @return array{message: string, step: string, errorCode: string, underlyingErrorCode: string} */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'step' => $this->step,
            'errorCode' => $this->errorCode,
            'underlyingErrorCode' => $this->underlyingErrorCode,
        ];
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
        $request->attributes->set('orbit.error_message', $this->getMessage());
        $request->attributes->set('orbit.command_result', $this->result);

        return ApiErrorResponse::make($request, $this->apiErrorCode(), $this->getMessage(), ['step' => $this->step, ...self::detailsIn($this), ...NodeCaddyBuildException::detailsIn($this)], GatewayExceptionStatus::for($this));
    }
}
