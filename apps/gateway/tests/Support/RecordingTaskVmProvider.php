<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\VmObservation;
use App\Models\TaskVm;
use RuntimeException;
use Throwable;

/**
 * A provider whose VMs are always running and ready. It records each VM it destroys, so a test can count workspace
 * removals of task VM groups, and throws `$destroyFailure` from `destroy` while it is set.
 */
final class RecordingTaskVmProvider implements TaskVmProvider
{
    /** @var list<string> */
    public array $destroyed = [];

    public ?Throwable $destroyFailure = null;

    public function create(TaskVm $vm, string $userData): void {}

    public function observe(TaskVm $vm): ?VmObservation
    {
        return new VmObservation(true, $vm->address ?? '10.251.77.20');
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        return true;
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        throw new RuntimeException('Scheduler tests read no task VM host key.');
    }

    public function imageManifest(TaskVm $vm): ?string
    {
        return null;
    }

    public function start(TaskVm $vm): void {}

    public function stop(TaskVm $vm): void {}

    public function destroy(TaskVm $vm): void
    {
        $this->destroyed[] = $vm->name;
        if ($this->destroyFailure instanceof Throwable) {
            throw $this->destroyFailure;
        }
    }
}
