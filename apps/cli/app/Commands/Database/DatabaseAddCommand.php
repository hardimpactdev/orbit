<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Exceptions\GatewayApiException;

final class DatabaseAddCommand extends DatabaseGatewayCommand
{
    #[\Override]
    protected $signature = 'database:add
        {slug? : Database connection slug}
        {--driver= : Database driver}
        {--node= : Owning database node selector}
        {--host= : Database host}
        {--port= : Database port}
        {--database= : Database name}
        {--path= : SQLite database path}
        {--username= : Database username}
        {--password= : Database password}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Register a database connection through the gateway.';

    public function handle(): int
    {
        $slug = $this->requiredArgument('slug', 'slug', 'The database connection slug is required.');

        if (is_int($slug)) {
            return $slug;
        }

        try {
            $response = $this->gatewayPost('/api/database-connections', [
                'slug' => $slug,
                ...$this->connectionFieldPayload(),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $resolvedSlug = $this->connectionSlug($response, $slug);

        $this->line("Database connection '{$resolvedSlug}' created.");

        return self::SUCCESS;
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
