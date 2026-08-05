<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Workspaces\WorkspaceLogHumanRenderer;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final class WorkspaceRunLogCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'workspace:run:log
        {run? : Workspace run id}
        {--json}';

    #[\Override]
    protected $description = 'Show captured output for a workspace lifecycle run.';

    public function handle(WorkspaceLogHumanRenderer $renderer): int
    {
        $runId = $this->runId();

        if ($runId === null) {
            return self::FAILURE;
        }

        try {
            $response = $this->gatewayGet("/api/workspaces/runs/{$runId}/log");
        } catch (GatewayApiException $exception) {
            return $this->renderWorkspaceLogGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $run = $this->runPayload($response);

        if ($run === []) {
            return $this->renderWorkspaceLogFailure(
                'gateway_unavailable',
                'Gateway response did not include workspace run log data.',
            );
        }

        foreach ($renderer->lines($run) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function runId(): ?int
    {
        $value = $this->argument('run');

        if (! is_string($value) || trim($value) === '') {
            $this->renderWorkspaceLogFailure('validation_failed', 'Workspace run ID is required.', ['field' => 'run']);

            return null;
        }

        $value = trim($value);

        if (! ctype_digit($value) || (int) $value < 1) {
            $this->renderWorkspaceLogFailure('validation_failed', 'Workspace run ID must be a positive integer.', [
                'field' => 'run',
                'value' => $value,
            ]);

            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function runPayload(array $response): array
    {
        $success = $response['success'] ?? null;
        $data = is_array($success) ? $success['data'] ?? null : null;
        $run = is_array($data) ? $data['run'] ?? null : null;

        if (! is_array($run)) {
            return [];
        }

        /** @var array<string, mixed> $run */
        return $run;
    }

    private function renderWorkspaceLogGatewayFailure(GatewayApiException $exception): int
    {
        if ($exception->hasGatewayError()) {
            return $this->renderWorkspaceLogFailure(
                $exception->gatewayErrorCode() ?? $exception->cliFailureCode(),
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                $exception->gatewayErrorMeta(),
                $exception->gatewayErrorData(),
            );
        }

        return $this->renderWorkspaceLogFailure($exception->cliFailureCode(), $exception->getMessage());
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function renderWorkspaceLogFailure(string $code, string $message, array $meta = [], array $data = []): int
    {
        if ($this->wantsJson()) {
            return $this->renderFailure($code, $message, $meta, $data);
        }

        $this->line($message);

        return self::FAILURE;
    }
}
