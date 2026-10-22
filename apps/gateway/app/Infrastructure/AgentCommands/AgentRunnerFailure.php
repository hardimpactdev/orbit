<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Http\Responses\ApiErrorResponse;
use App\Http\Responses\RendersApiError;
use App\Infrastructure\Processes\CommandResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * No valid result arrived. Each kind is a distinct failure: the transport failed or timed out, the agent
 * printed no result, a malformed one, or an error code its contract does not declare. A lost result never
 * proves that no change happened.
 */
final class AgentRunnerFailure extends RuntimeException implements RendersApiError
{
    public const string Transport = 'transport';

    public const string Timeout = 'timeout';

    public const string MissingResult = 'missing_result';

    public const string MalformedResult = 'malformed_result';

    public const string UndeclaredError = 'undeclared_error';

    public const string RepeatedMismatch = 'repeated_mismatch';

    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly string $command,
        public readonly string $kind,
        string $message,
        public readonly array $details = [],
        public readonly ?CommandResult $result = null,
    ) {
        parent::__construct($message);
    }

    #[\Override]
    public function apiErrorCode(): string
    {
        return 'agent.runner_failed';
    }

    #[\Override]
    public function render(Request $request): JsonResponse
    {
        $request->attributes->set('orbit.error_code', $this->apiErrorCode());
        $request->attributes->set('orbit.command_result', $this->result);

        return ApiErrorResponse::make($request, $this->apiErrorCode(), $this->getMessage(), ['command' => $this->command, 'kind' => $this->kind, ...$this->details], 502);
    }
}
