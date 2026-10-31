<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** Request-scoped, reentrant admission fence shared with existing router teardown. */
final class TaskVmModelAdmission
{
    private bool $held = false;

    /**
     * @template TReturn
     *
     * @param  Closure():TReturn  $admit
     * @return TReturn
     */
    public function run(Closure $admit): mixed
    {
        if ($this->held) {
            return $admit();
        }
        $lock = Cache::lock(ModelKeyIssuer::Lock, 600);
        try {
            $lock->block(60);
        } catch (LockTimeoutException $exception) {
            throw new ResourceOperationException('ai_router.keys_busy', 'Router admission or teardown is still running.', 409, previous: $exception);
        }
        $this->held = true;
        try {
            return $admit();
        } finally {
            $this->held = false;
            $lock->release();
        }
    }
}
