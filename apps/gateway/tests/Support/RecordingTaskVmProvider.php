<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\VmObservation;
use App\Models\TaskVm;
use Closure;
use Throwable;

/**
 * A task VM provider for tests. It records each call in `$calls` by its operation, and in `$vmCalls` with the VM's
 * name. `observe` answers from the `$observations` queue first, then with `$observation`.
 */
final class RecordingTaskVmProvider implements TaskVmProvider
{
    public const string Fingerprint = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> each call as "<operation> <VM name>" */
    public array $vmCalls = [];

    /** @var list<VmObservation|null> */
    public array $observations = [];

    /** What `observe` answers once `$observations` is empty. */
    public ?VmObservation $observation;

    /** Whether cloud-init is done, or the failure its guest command throws. */
    public bool|Throwable $ready = true;

    public ?string $gatewayPublicKey = null;

    public ?Throwable $startError = null;

    /** @var (Closure(TaskVm): mixed)|null */
    public ?Closure $onCreate = null;

    /** @var (Closure(TaskVm): mixed)|null */
    public ?Closure $onDestroy = null;

    public function __construct()
    {
        $this->observation = new VmObservation(true, '10.251.77.20');
    }

    public function create(TaskVm $vm, string $gatewayPublicKey): void
    {
        $this->record('create', $vm);
        $this->gatewayPublicKey = $gatewayPublicKey;
        if ($this->onCreate instanceof Closure) {
            ($this->onCreate)($vm);
        }
    }

    public function observe(TaskVm $vm): ?VmObservation
    {
        $this->record('observe', $vm);

        return $this->observations === [] ? $this->observation : array_shift($this->observations);
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        $this->record('fingerprint', $vm);

        return self::Fingerprint;
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        $this->record('cloud-init', $vm);
        if ($this->ready instanceof Throwable) {
            throw $this->ready;
        }

        return $this->ready;
    }

    public function start(TaskVm $vm): void
    {
        $this->record('start', $vm);
        if ($this->startError instanceof Throwable) {
            throw $this->startError;
        }
    }

    public function stop(TaskVm $vm): void
    {
        $this->record('stop', $vm);
    }

    public function destroy(TaskVm $vm): void
    {
        $this->record('destroy', $vm);
        if ($this->onDestroy instanceof Closure) {
            ($this->onDestroy)($vm);
        }
    }

    private function record(string $operation, TaskVm $vm): void
    {
        $this->calls[] = $operation;
        $this->vmCalls[] = "{$operation} {$vm->name}";
    }
}
