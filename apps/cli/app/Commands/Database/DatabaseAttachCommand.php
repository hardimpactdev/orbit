<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Exceptions\GatewayApiException;

final class DatabaseAttachCommand extends DatabaseGatewayCommand
{
    #[\Override]
    protected $signature = 'database:attach
        {connection? : Database connection slug}
        {--app= : App selector}
        {--workspace= : Workspace selector}
        {--env-prefix=DB : Environment variable prefix}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Attach a database connection to an app or workspace target.';

    public function handle(): int
    {
        $connection = $this->requiredArgument('connection', 'connection', 'The database connection argument is required.');

        if (is_int($connection)) {
            return $connection;
        }

        $payload = $this->targetPayload();

        if (is_int($payload)) {
            return $payload;
        }

        try {
            $response = $this->gatewayPost('/api/database-connections/'.rawurlencode($connection).'/targets', $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
