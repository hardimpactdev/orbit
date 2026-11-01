<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Observation\ScheduledObservation;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Infrastructure\Swamp\SwampScheduledResults;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (['saved', 'deleted'] as $event) {
            Event::listen('eloquent.'.$event.': *', static function (string $name, array $models): void {
                $model = $models[0] ?? null;
                // Collector-only rows (Activity, Task, cursors) refresh with the next terminal, not per commit.
                if ($model !== null && in_array($model::class, [
                    Node::class, NodeRole::class, Process::class, Instance::class, TaskVm::class,
                ], true)) {
                    app(ScheduledObservation::class)->invalidate();
                }
            });
        }
    }

    public function register(): void
    {
        $this->app->singleton(OperationKinds::class);
        $this->app->singleton(DirectOperation::class);
        $this->app->scoped(OperationIdempotency::class);
        $this->app->singleton(SwampRunSubscriber::class, static fn ($app): SwampRunSubscriber => new SwampRunSubscriber(
            StreamWebSocketClient::forSwamp(),
            $app->make(LoggerInterface::class),
            scheduled: new SwampScheduledResults(StreamWebSocketClient::forSwamp(), $app->make(LoggerInterface::class)),
        ));
    }
}
