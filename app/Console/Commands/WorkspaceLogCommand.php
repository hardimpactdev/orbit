<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\ShowWorkspaceLogRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceLogResponse;
use App\Models\WorkspaceRun;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Workspaces\WorkspaceLogPayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('workspace:log
    {run? : Workspace run id}
    {--json : Output JSON}')]
#[Description('Show captured output for a workspace lifecycle run')]
class WorkspaceLogCommand extends Command
{
    public function handle(WorkspaceLogPayload $payload): int
    {
        $runId = $this->parseRunId();

        if ($runId === false) {
            return self::FAILURE;
        }

        $callerRole = app(CallerRoleResolver::class)->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        try {
            $run = $this->fetchRun($runId, $callerRole, $payload);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to read workspace run logs.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to read workspace run logs.',
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['run' => $run], ['registry_only' => true]);
        }

        $this->renderHuman($run);

        return self::SUCCESS;
    }

    private function parseRunId(): int|false
    {
        $value = $this->argument('run');

        if (! is_string($value) || trim($value) === '') {
            $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace run ID is required.',
                meta: ['field' => 'run'],
            );

            return false;
        }

        $value = trim($value);

        if (! ctype_digit($value) || (int) $value < 1) {
            $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace run ID must be a positive integer.',
                meta: [
                    'field' => 'run',
                    'value' => $value,
                ],
            );

            return false;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRun(int $runId, string $callerRole, WorkspaceLogPayload $payload): array
    {
        if ($callerRole !== 'gateway') {
            /** @var WorkspaceLogResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new ShowWorkspaceLogRequest(run: $runId))
                ->dto();

            return $dto->run;
        }

        $run = WorkspaceRun::query()
            ->with(['workspace.app.node', 'runSteps.step'])
            ->whereKey($runId)
            ->first();

        if (! $run instanceof WorkspaceRun) {
            throw new GatewayApiException(
                message: "Workspace run {$runId} not found.",
                errorCode: 'workspace.run_not_found',
                errorMeta: ['id' => $runId],
            );
        }

        return $payload->forRun($run);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function renderHuman(array $run): void
    {
        $this->line("Workspace Log Run #{$run['id']}  ({$run['app']}/{$run['workspace']} on {$run['node']})");

        $steps = is_array($run['steps'] ?? null) ? $run['steps'] : [];

        if ($steps === []) {
            $this->line('No step output recorded.');
            $this->line($this->footer($run));

            return;
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $this->renderStep($step);
        }

        $this->newLine();
        $this->line($this->footer($run));
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function renderStep(array $step): void
    {
        $status = is_string($step['status'] ?? null) ? $step['status'] : 'skipped';
        $name = is_string($step['name'] ?? null) ? $step['name'] : 'Step';
        $command = is_string($step['command'] ?? null) ? $step['command'] : null;
        $label = $command !== null && $command !== $name ? "{$name} ({$command})" : $name;

        $this->line("{$this->statusIcon($status)} {$label}");

        if ($status === 'failure' && $step['exit_code'] !== null) {
            $this->line("  [EXIT CODE {$step['exit_code']}]");
        }

        if ($status === 'skipped') {
            return;
        }

        $stdout = is_string($step['stdout'] ?? null) ? $step['stdout'] : '';
        $stderr = is_string($step['stderr'] ?? null) ? $step['stderr'] : '';

        if ($stdout !== '') {
            $this->renderOutputBlock('STDOUT', $stdout);
        }

        if ($stderr !== '') {
            $this->renderOutputBlock('STDERR', $stderr);
        }
    }

    private function renderOutputBlock(string $label, string $output): void
    {
        $this->newLine();
        $this->line("  {$label}:");

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $this->line("  > {$line}");
        }
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'success' => '✔',
            'failure' => '✘',
            default => '·',
        };
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function footer(array $run): string
    {
        $startedAt = is_string($run['started_at'] ?? null)
            ? Carbon::parse($run['started_at'])->format('Y-m-d H:i:s')
            : 'unknown';
        $duration = is_int($run['duration_ms'] ?? null)
            ? $this->formatDuration($run['duration_ms'])
            : 'running';

        return "Run #{$run['id']} {$run['status']} (started {$startedAt}, duration {$duration})";
    }

    private function formatDuration(int $durationMs): string
    {
        $seconds = $durationMs / 1000;

        if ($seconds < 60) {
            return rtrim(rtrim(number_format($seconds, 1), '0'), '.').'s';
        }

        $minutes = (int) floor($seconds / 60);

        if ($minutes < 60) {
            return "{$minutes}m";
        }

        return intdiv($minutes, 60).'h';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
