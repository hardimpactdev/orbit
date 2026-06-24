<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Exceptions\GatewayApiException;

final class DatabaseAddUserCommand extends DatabaseGatewayCommand
{
    #[\Override]
    protected $signature = 'database:add-user
        {connection? : Database connection slug to create or update}
        {--service= : Managed MySQL process name}
        {--node= : Node owning the managed MySQL process}
        {--database= : MySQL database name}
        {--username= : MySQL username}
        {--password= : MySQL password}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Create or update a MySQL database user through a managed MySQL process.';

    public function handle(): int
    {
        $connection = $this->requiredArgument('connection', 'connection', 'The database connection slug is required.');

        if (is_int($connection)) {
            return $connection;
        }

        $payload = $this->payload();

        foreach (['service', 'database', 'username', 'password'] as $field) {
            if (! array_key_exists($field, $payload)) {
                return $this->failValidation($field, "The --{$field} option is required.");
            }
        }

        try {
            $response = $this->gatewayPost('/api/database-connections/'.rawurlencode($connection).'/users', $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $resolvedSlug = $this->connectionSlug($response, $connection);
        $username = (string) $payload['username'];
        $service = (string) $payload['service'];

        $this->line("Database user '{$username}' ready on service '{$service}'.");
        $this->line("Database connection '{$resolvedSlug}' updated.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return $this->filledPayload([
            'service' => $this->stringOption('service'),
            'node' => $this->stringOption('node'),
            'database' => $this->stringOption('database'),
            'username' => $this->stringOption('username'),
            'password' => $this->stringOption('password'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function connectionSlug(array $response, string $fallback): string
    {
        $data = $response['success']['data'] ?? null;
        $connection = is_array($data) ? $data['connection'] ?? null : null;
        $slug = is_array($connection) ? $connection['slug'] ?? null : null;

        return is_string($slug) && $slug !== '' ? $slug : $fallback;
    }
}
