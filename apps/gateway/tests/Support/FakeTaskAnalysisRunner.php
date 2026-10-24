<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskAnalysisRunner;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Models\Instance;

final class FakeTaskAnalysisRunner implements TaskAnalysisRunner
{
    public int $starts = 0;

    public int $baseReads = 0;

    public ?string $initialBase = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var array<string, mixed> */
    public array $completion = [];

    public int $recovers = 0;

    public int $cancels = 0;

    public bool $loseStartReply = false;

    public bool $uncertain = false;

    public bool $terminal = true;

    public ?string $bytes = null;

    public ?string $changedTree = null;

    /** @var (\Closure(): void)|null */
    public ?\Closure $beforeInspect = null;

    public string $status = 'ready';

    public string $head = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public string $tree = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public string $entrypoint = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** @var array<string, mixed> */
    public array $input = [];

    /** @var array<string, TaskCheckProcess> */
    public array $processes = [];

    /** @var array<string, bool> */
    public array $tombstones = [];

    public function initialAnalysisBase(Instance $instance): ?string
    {
        $this->baseReads++;

        return $this->initialBase;
    }

    public function inspectAnalysis(Instance $instance, string $base): array
    {
        ($this->beforeInspect)?->__invoke();

        return ['base' => $base, 'head' => $this->head, 'tree' => $this->tree, 'branch' => $instance->branch ?? 'task-'.$instance->task_workspace_owner_id, 'entrypoint_sha256' => $this->entrypoint];
    }

    public function startAnalysis(Instance $instance, array $input, string $runId): TaskCheckProcess
    {
        if (isset($this->tombstones[$runId])) {
            throw new TaskCheckException('Reserved start was tombstoned.');
        }
        $this->starts++;
        $this->input = $input;
        $process = $this->processes[$runId] = new TaskCheckProcess(4001, 'start', $this->head, $this->tree, $runId);
        if ($this->loseStartReply) {
            $this->loseStartReply = false;
            throw new TaskCheckException('Lost start reply.');
        }

        return $process;
    }

    public function recoverStart(Instance $instance, string $runId): ?TaskCheckProcess
    {
        $this->recovers++;
        if ($this->uncertain) {
            throw new TaskCheckException('Unknown supervisor state.');
        }
        if (! isset($this->processes[$runId])) {
            $this->tombstones[$runId] = true;
        }

        return $this->processes[$runId] ?? null;
    }

    public function readAnalysis(Instance $instance, TaskCheckProcess $process): array
    {
        if ($this->uncertain || ! $this->terminal) {
            return ['state' => 'running'];
        }
        $bytes = $this->bytes ?? json_encode(['schema' => 1, ...array_intersect_key($this->input, array_flip(['execution_id', 'plan_sha256', 'base', 'head', 'tree'])), 'status' => $this->status, 'diagnostics' => [], 'report' => ['schema' => 'test.repository.v1', 'data' => (object) ['retained' => 'complete']]], JSON_THROW_ON_ERROR);

        return ['state' => 'finished', 'result' => array_replace(['analysis' => true, 'report_bytes' => strlen($bytes), 'report_base64' => base64_encode($bytes), 'head_after' => $this->head, 'tree_after' => $this->changedTree ?? $this->tree, 'exit_code' => 0, 'started_at' => '2027-01-01T00:00:00Z', 'finished_at' => '2027-01-01T00:00:01Z', 'duration_ms' => 1000], $this->completion)];
    }

    public function cancel(Instance $instance, TaskCheckProcess $process): void
    {
        $this->cancels++;
        if ($this->uncertain) {
            throw new TaskCheckException('Descendants are unknown.');
        }
        $this->terminal = true;
    }
}
