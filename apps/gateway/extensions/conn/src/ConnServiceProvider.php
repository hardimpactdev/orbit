<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Activity\ActivityInputRedaction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Instances\InstanceThreadContribution;
use App\Domain\Instances\Power\InstanceAgentActivity;
use App\Domain\Nodes\NodeAccessRemovalHook;
use App\Domain\Nodes\NodeAccessRemovalTransactionHook;
use App\Domain\Nodes\NodeRemovalHook;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\OperationRecordCoordinator;
use App\Domain\Operations\OperationSecretSource;
use App\Providers\ExtensionServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Console\EnrolConnManagedTargetCommand;
use Orbit\Extensions\Conn\Console\ReadConnThreadsCommand;
use Orbit\Extensions\Conn\Console\RenewConnEnvironmentsCommand;
use Orbit\Extensions\Conn\Domain\ConnAgentActivity;
use Orbit\Extensions\Conn\Domain\ConnDirectOperations;
use Orbit\Extensions\Conn\Domain\ConnInstanceLifecycle;
use Orbit\Extensions\Conn\Domain\ConnLaunchDispatchSource;
use Orbit\Extensions\Conn\Domain\ConnLaunchSettlement;
use Orbit\Extensions\Conn\Domain\ConnNodeAccessRemoval;
use Orbit\Extensions\Conn\Domain\ConnNodeRemoval;
use Orbit\Extensions\Conn\Domain\ConnThreadIndex;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedNoticePublisher;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedRevocation;
use Orbit\Extensions\Conn\Domain\Managed\ReverbConnManagedNoticePublisher;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Infrastructure\ConnActivityInputRedaction;
use Orbit\Extensions\Conn\Infrastructure\HttpT3ServerClient;

/** The conn extension: Conn devices, shared profiles, and T3 server pairing ([Conn layer](/reference/conn)). */
final class ConnServiceProvider extends ExtensionServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        T3ServerClient::class => HttpT3ServerClient::class,
        ConnManagedNoticePublisher::class => ReverbConnManagedNoticePublisher::class,
    ];

    public function name(): string
    {
        return 'conn';
    }

    public function description(): string
    {
        return 'Conn devices sign in as Nodes, share settings through profiles, and pair with registered T3 servers.';
    }

    public function register(): void
    {
        $this->contribute(DirectOperationContribution::class, ConnDirectOperations::class);
        $this->contribute(OperationSecretSource::class, ConnLaunchDispatchSource::class);
        $this->contribute(OperationRecordCoordinator::class, ConnLaunchSettlement::class);
        $this->contribute(ActivityInputRedaction::class, ConnActivityInputRedaction::class);
        $this->contribute(InstanceLifecycleContribution::class, ConnInstanceLifecycle::class);
        $this->contribute(InstanceAgentActivity::class, ConnAgentActivity::class);
        $this->contribute(NodeAccessRemovalHook::class, ConnNodeAccessRemoval::class);
        $this->contribute(NodeAccessRemovalTransactionHook::class, ConnManagedRevocation::class);
        $this->contribute(NodeRemovalHook::class, ConnNodeRemoval::class);
        $this->contribute(InstanceThreadContribution::class, ConnThreadIndex::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        // The profile channel auth compares the exact name it was sent (/reference/conn#profile-events), so it is not trimmed.
        TrimStrings::skipWhen(static fn (Request $request): bool => $request->is('api/v1/conn/broadcasting/auth'));
        $this->commands([RenewConnEnvironmentsCommand::class, ReadConnThreadsCommand::class, EnrolConnManagedTargetCommand::class]);
        // Hourly, so a container started after its session ended soon gets a new one; each tick admits only the due ones.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            GatewaySchedule::command($schedule, 'conn:environments:renew', uniqueFor: 3000, overlapMinutes: 50)->hourly()
                ->when(static fn (): bool => app(ExtensionStore::class)->enabled('conn'));
            // Every minute while the operator's switch is on, on `problems`, so it waits behind task work and never on `operations`.
            GatewaySchedule::command($schedule, 'conn:threads:read', uniqueFor: 120, overlapMinutes: 2, queue: GatewayQueue::Problems)->everyMinute()
                ->when(static fn (): bool => ConnThreadIndex::reads() && app(ExtensionStore::class)->enabled('conn'));
        });
    }
}
