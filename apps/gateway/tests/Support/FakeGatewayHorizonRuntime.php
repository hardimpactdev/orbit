<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Gateway\GatewayHorizonRuntime;

/** A Horizon host for tests: an old master and its `task-vms` worker, and a new master after the terminate. */
final class FakeGatewayHorizonRuntime implements GatewayHorizonRuntime
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $masterNames = [];

    /** @var list<int> */
    public array $workers = [];

    /** How many checks the old worker keeps running after the terminate; null for never ending. */
    public ?int $workerChecksLeft = 2;

    /** Whether a new master registers after the terminate. */
    public bool $newMasterStarts = true;

    public bool $terminated = false;

    /** @var (\Closure(): void)|null runs on terminate, for tests that order it against other calls */
    public ?\Closure $onTerminate = null;

    public function taskVmWorkers(): array
    {
        return $this->workers;
    }

    public function running(int $pid): bool
    {
        if (! $this->terminated || $this->workerChecksLeft === null) {
            return true;
        }

        if ($this->workerChecksLeft > 0) {
            $this->workerChecksLeft--;

            return true;
        }

        $this->calls[] = 'worker-gone';

        return false;
    }

    public function masters(): array
    {
        return $this->masterNames;
    }

    public function pauseTaskVms(int $seconds): void
    {
        $this->calls[] = 'pause:'.$seconds;
    }

    public function resumeTaskVms(): void
    {
        $this->calls[] = 'resume';
    }

    public function terminate(): void
    {
        $this->calls[] = 'terminate';
        $this->terminated = true;
        if ($this->onTerminate !== null) {
            ($this->onTerminate)();
        }
        $this->masterNames = $this->newMasterStarts ? ['new-master'] : [];
    }

    public function sleep(int $milliseconds): void {}
}
