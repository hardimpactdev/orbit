<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskScriptRunner;
use App\Models\Instance;

final class FakeTaskScriptRunner implements TaskScriptRunner
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

    public function inspectScript(Instance $instance, string $script): array
    {
        ($this->beforeInspect)?->__invoke();

        return ['head' => $this->head, 'tree' => $this->tree, 'branch' => $instance->branch ?? 'task-'.$instance->task_workspace_owner_id, 'entrypoint_sha256' => $this->entrypoint];
    }

    public function startScript(Instance $instance, array $input, string $runId): TaskCheckProcess
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

    public function readScript(Instance $instance, TaskCheckProcess $process): array
    {
        if ($this->uncertain || ! $this->terminal) {
            return ['state' => 'running'];
        }

        return ['state' => 'finished', 'output' => $this->bytes ?? '', 'result' => array_replace(['script' => true,
            'output_bytes' => strlen($this->bytes ?? ''), 'head_after' => $this->head, 'tree_after' => $this->changedTree ?? $this->tree, 'exit_code' => 0], $this->completion)];
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
