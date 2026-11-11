<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Processes\ProcessOperationException;
use App\Models\Instance;
use App\Models\Process;
use Closure;
use Throwable;

final readonly class ViteProcessLifecycle
{
    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(): void */
    private Closure $wait;

    /**
     * @param  (Closure(): float)|null  $clock
     * @param  (Closure(): void)|null  $wait
     */
    public function __construct(private AppDevSourceOperationLock $owner, private VitePortAllocator $ports, private VitePortRuntime $runtime, ?Closure $clock = null, ?Closure $wait = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->wait = $wait ?? static function (): void {
            usleep(250_000);
        };
    }

    /**
     * @param  Closure(): void  $launch
     * @param  Closure(): void  $stop
     */
    public function run(Process $process, Closure $launch, Closure $stop, bool $start, bool $restart = false, bool $explicitStart = false, bool $recordedPortOnly = false): void
    {
        $instance = Instance::query()->with('node')->findOrFail($process->owner_id);
        $this->owner->synchronized($instance->node_id, function () use ($process, $instance, $launch, $stop, $start, $restart, $explicitStart, $recordedPortOnly): void {
            try {
                $port = $recordedPortOnly ? $instance->vite_port : $this->ports->assign($instance);
                if ($port === null) {
                    throw new ProcessOperationException('vite-prepare', 'vite.development_required', 'The Vite preset requires a development Instance.');
                }
                if ($recordedPortOnly && ($port < 1024 || $port > 65535)) {
                    throw $this->recordedPortUnavailable($port);
                }
                $owned = $this->runtime->ownsListener($process, $instance, $port);
                if ($owned && $start && ! $restart && $this->runtime->ready($process, $instance, $port)) {
                    if ($explicitStart) {
                        $this->runtime->markAwake($instance);
                    }

                    return;
                }

                $this->runtime->suspendTraffic($instance);
                if ($restart) {
                    $stop();
                    $owned = false;
                }
                $deadline = ($this->clock)() + 50;
                for ($attempt = 0; $attempt < ($recordedPortOnly ? 1 : 3); $attempt++) {
                    if (! $owned) {
                        if ($recordedPortOnly) {
                            try {
                                $available = $this->runtime->selectPort($instance->node, $port, VitePortAllocator::EXCLUDED_PORTS);
                            } catch (ProcessOperationException $exception) {
                                if ($exception->errorCode === 'vite.ports_exhausted') {
                                    throw $this->recordedPortUnavailable($port, $exception);
                                }

                                throw $exception;
                            }
                            if ($available !== $port) {
                                throw $this->recordedPortUnavailable($port);
                            }
                        } else {
                            $port = $this->ports->assign($instance, recheck: true);
                        }
                    }
                    $instance->refresh();
                    $this->runtime->prepare($process, $instance);
                    if (! $recordedPortOnly) {
                        $this->runtime->project($instance);
                    }
                    try {
                        $launch();
                    } catch (ProcessOperationException $exception) {
                        if ($recordedPortOnly && $exception->errorCode === 'process.start_failed') {
                            throw $this->recordedPortUnavailable($port, $exception);
                        }

                        throw $exception;
                    }
                    if (! $start) {
                        return;
                    }

                    $attemptDeadline = min($deadline, ($this->clock)() + 15);
                    do {
                        if ($this->runtime->ready($process, $instance, (int) $port)) {
                            if ($explicitStart && ! $restart) {
                                $this->runtime->markAwake($instance);
                            }

                            return;
                        }
                        ($this->wait)();
                    } while (($this->clock)() < $attemptDeadline);

                    $owned = $this->runtime->ownsListener($process, $instance, (int) $port);
                    if ($recordedPortOnly || $owned || ($this->clock)() >= $deadline) {
                        break;
                    }
                    $stop();
                    $replacement = $this->ports->assign($instance, recheck: true);
                    if ($replacement === $port) {
                        break;
                    }
                }

                $stop();
                if ($recordedPortOnly) {
                    throw $this->recordedPortUnavailable($port);
                }
                throw new ProcessOperationException('vite-readiness', 'vite.not_ready', 'The owned Vite endpoint did not become ready. Check the Process logs and retry.');
            } catch (RuntimeConvergenceException $exception) {
                throw new ProcessOperationException($exception->step, $exception->errorCode, $exception->getMessage(), previous: $exception);
            }
        });
    }

    private function recordedPortUnavailable(int $port, ?Throwable $previous = null): ProcessOperationException
    {
        return new ProcessOperationException('vite-readiness', 'vite.recorded_port_unavailable',
            "The recorded port {$port} is not available. Run process:start for this Process to recheck and publish its port.", previous: $previous);
    }
}
