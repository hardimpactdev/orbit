<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class DatabaseShowCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'database:show
        {connection? : Database connection slug}
        {--json}';

    #[\Override]
    protected $description = 'Show one database connection from the registry.';

    public function handle(): int
    {
        $connection = $this->stringArgument('connection');

        if ($connection === null) {
            return $this->renderFailure('validation_failed', 'The connection argument is required.', ['field' => 'connection']);
        }

        try {
            $response = $this->gatewayGet('/api/database-connections/'.rawurlencode($connection));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderDatabaseShowSuccess($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderDatabaseShowSuccess(array $response): int
    {
        $data = $this->successData($response);
        $connectionPayload = is_array($data['connection'] ?? null) ? $data['connection'] : [];

        return $this->renderSuccess(['connection' => $connectionPayload]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }
}
