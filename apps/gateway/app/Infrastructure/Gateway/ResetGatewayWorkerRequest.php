<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Logging\RequestLogContext;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\DatabaseManager;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use PDO;
use Throwable;

/** Release request-owned state before FrankenPHP dispatches the next caller. */
final readonly class ResetGatewayWorkerRequest
{
    public function handle(RequestTerminated|WorkerErrorOccurred $event): void
    {
        try {
            if ($event->sandbox->resolved('db')) {
                $databases = $event->sandbox->make(DatabaseManager::class);
                foreach ($databases->getConnections() as $name => $connection) {
                    try {
                        if ($connection->transactionLevel() > 0) {
                            $connection->rollBack(0);
                        } elseif (($pdo = $connection->getRawPdo()) instanceof PDO && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    } catch (Throwable $failure) {
                        $databases->purge($name);
                        report($failure);
                    }
                }
            }

            ActivityShutdownFinalizer::finalizeArmed();
            RequestLogContext::clear();
        } finally {
            // Finalizing an activity can still send a record. Release the signing driver after it sends.
            $event->sandbox->make(BroadcastManager::class)->purge('reverb');
        }
    }
}
