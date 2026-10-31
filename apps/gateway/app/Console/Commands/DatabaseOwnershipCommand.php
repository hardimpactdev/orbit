<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DatabaseConnections\ManageDatabaseOwnershipAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Support\ValidatedData;
use Illuminate\Console\Command;

/** Local operator command; deliberately absent from the API and public command registry. */
final class DatabaseOwnershipCommand extends Command
{
    #[\Override]
    protected $signature = 'database:ownership {action : adopt or forget} {connection : Database connection slug}
        {--actor= : Operator responsible for this decision} {--reason= : Reason for adoption or forgetting}';

    #[\Override]
    protected $description = 'Prove existing MySQL ownership or forget a connection without dropping data';

    public function handle(ManageDatabaseOwnershipAction $ownership): int
    {
        $connection = DatabaseConnection::query()->where('slug', $this->argument('connection'))->first();
        if ($connection === null) {
            $this->error('database.not_found: The connection does not exist.');

            return self::FAILURE;
        }
        try {
            $action = ValidatedData::string($this->argument('action'));
            $ownership->execute($action, $connection, ValidatedData::string($this->option('actor') ?? ''), ValidatedData::string($this->option('reason') ?? ''));
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->info($action === 'adopt' ? 'Database ownership adopted ['.$connection->slug.'].' : 'Connection forgotten ['.$connection->slug.']; database and users unchanged.');

        return self::SUCCESS;
    }
}
