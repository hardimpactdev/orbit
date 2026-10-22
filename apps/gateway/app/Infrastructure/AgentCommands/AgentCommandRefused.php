<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Http\Responses\ApiErrorResponse;
use App\Http\Responses\RendersApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A declared refusal of an agent command: its code is in the command's contract or the common list.
 * A domain action maps the codes it expects to its own public errors once; an unmapped refusal renders as is.
 */
final class AgentCommandRefused extends RuntimeException implements RendersApiError
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly string $command,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    #[\Override]
    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }

    #[\Override]
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', $this->errorCode);

        return ApiErrorResponse::make($request, $this->errorCode, $this->getMessage(), ['command' => $this->command, ...$this->details], str_starts_with($this->errorCode, 'agent.') ? 502 : 422);
    }
}
