<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use Illuminate\Container\Attributes\Tag;

final readonly class DestroyDatabaseConnectionAction
{
    /** @param iterable<DatabaseConnectionRemovalGuard> $guards */
    public function __construct(
        private DropServerDatabaseAction $drops,
        #[Tag(DatabaseConnectionRemovalGuard::class)] private iterable $guards,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function execute(DatabaseConnection $connection): void
    {
        if ($connection->targets()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'database.connection_attached',
                message: "Detach Database connection [{$connection->slug}] from every Instance before removing it.",
                status: 409,
            );
        }

        foreach ($this->guards as $guard) {
            $guard->assertRemovable($connection);
        }

        $this->drops->execute($connection);
        $connection->delete();

        $this->broadcaster->broadcast(
            RecordEventType::DatabaseDeleted,
            $connection->id,
            ['id' => $connection->id, 'slug' => $connection->slug],
        );
    }
}
