<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Exceptions\GatewayApiException;

final class DatabaseRemoveCommand extends DatabaseGatewayCommand
{
    #[\Override]
    protected $signature = 'database:remove
        {connection? : Database connection slug}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a database connection through the gateway.';

    public function handle(): int
    {
        $connection = $this->requiredArgument('connection', 'connection', 'The database connection argument is required.');

        if (is_int($connection)) {
            return $connection;
        }

        $consent = $this->confirmRemoval();

        if (is_int($consent)) {
            return $consent;
        }

        try {
            $response = $this->gatewayDelete('/api/database-connections/'.rawurlencode($connection), [
                'force' => true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $resolvedSlug = $this->removedConnectionSlug($response, $connection);

        $this->line("Database connection '{$resolvedSlug}' removed.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function removedConnectionSlug(array $response, string $fallback): string
    {
        $data = $response['success']['data'] ?? null;
        $result = is_array($data) ? ($data['result'] ?? null) : null;
        $slug = is_array($result) ? ($result['connection'] ?? null) : null;

        return is_string($slug) && $slug !== '' ? $slug : $fallback;
    }

    private function confirmRemoval(): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->failValidation('force', 'Use --force to remove this database connection.', [
                'reason' => 'destructive_consent_required',
            ]);
        }

        if ($this->confirm('Remove database connection and all target mappings?', default: false)) {
            return null;
        }

        return $this->failValidation('force', 'Operation cancelled.', [
            'reason' => 'destructive_consent_required',
        ]);
    }
}
