<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Exceptions\GatewayApiException;

final class DatabaseUpdateCommand extends DatabaseGatewayCommand
{
    #[\Override]
    protected $signature = 'database:update
        {connection? : Database connection slug}
        {--slug= : New database connection slug}
        {--driver= : Database driver}
        {--node= : Owning database node selector}
        {--host= : Database host}
        {--port= : Database port}
        {--database= : Database name}
        {--path= : SQLite database path}
        {--username= : Database username}
        {--password= : Database password}
        {--clear-password : Clear the stored password}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update a database connection through the gateway.';

    public function handle(): int
    {
        $connection = $this->requiredArgument('connection', 'connection', 'The database connection argument is required.');

        if (is_int($connection)) {
            return $connection;
        }

        $payload = $this->payload();

        if ($payload === []) {
            return $this->failValidation('payload', 'At least one mutable database connection field is required.');
        }

        try {
            $response = $this->gatewayPatch('/api/database-connections/'.rawurlencode($connection), $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $payload = $this->filledPayload([
            'slug' => $this->stringOption('slug'),
            ...$this->connectionFieldPayload(),
        ]);

        if ($this->option('clear-password') === true) {
            $payload['clear_password'] = true;
        }

        return $payload;
    }
}
