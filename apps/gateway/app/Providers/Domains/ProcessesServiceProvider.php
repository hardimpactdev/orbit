<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationSecrets;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessUsageIndex;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\LockRenewingProcessRunner;
use App\Infrastructure\Processes\NativeProcessAdmissionLock;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\NativeProcessRuntimeLease;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\PrometheusProcessRuntimeStatusIndex;
use App\Infrastructure\Processes\PrometheusProcessUsageIndex;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class ProcessesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ProcessRuntimeManager::class => RemoteProcessRuntimeManager::class,
        ProcessRuntimeStatusIndex::class => PrometheusProcessRuntimeStatusIndex::class,
        ProcessUsageIndex::class => PrometheusProcessUsageIndex::class,
    ];

    public function register(): void
    {
        foreach (['process.unit_content', 'process.environment', 'process.command'] as $source) {
            $this->app->bind('operation.secret.'.$source, ProcessOperationSecrets::class);
        }
        $this->app->bind(
            ProcessRunner::class,
            static fn ($app): ProcessRunner => new LockRenewingProcessRunner(
                $app->make(NativeProcessRunner::class),
                $app->make(NodeLocks::class),
            ),
        );
        $this->app->scoped(
            ProcessAdmissionLock::class,
            static fn (): ProcessAdmissionLock => new NativeProcessAdmissionLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/process-admission',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(ProcessRuntimeLease::class, NativeProcessRuntimeLease::class);
        $this->app->singleton(CommandDeadline::class);
    }
}
