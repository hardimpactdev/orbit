<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Exceptions\GatewayApiException;

final class DatabaseDetachCommand extends DatabaseGatewayCommand
{
    #[\Override]
    protected $signature = 'database:detach
        {connection? : Database connection slug}
        {--instance= : Instance selector (app.instance)}
        {--workspace= : Workspace selector}
        {--env-prefix=DB : Environment variable prefix}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Detach a database connection from an instance or workspace target.';

    public function handle(): int
    {
        $connection = $this->requiredArgument(
            'connection',
            'connection',
            'The database connection argument is required.',
        );

        if (is_int($connection)) {
            return $connection;
        }

        $payload = $this->targetPayload();

        if (is_int($payload)) {
            return $payload;
        }

        try {
            $response = $this->gatewayDelete(
                '/api/database-connections/'.rawurlencode($connection).'/targets',
                $payload,
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $result = $this->detachResult($response);
        $slug = $this->resultString($result, 'connection') ?? $connection;
        $targetType = $this->resultString($result, 'target_type') ?? $this->humanTargetType();
        $target = $this->resultString($result, 'target') ?? $this->humanTarget();
        $envPrefix = $this->resultString($result, 'env_prefix') ?? $this->stringOption('env-prefix') ?? 'DB';

        $this->line("Detached database connection '{$slug}' from {$targetType} '{$target}' prefix '{$envPrefix}'.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function detachResult(array $response): array
    {
        $data = $response['success']['data'] ?? null;
        $result = is_array($data) ? $data['result'] ?? null : null;

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function resultString(array $result, string $key): ?string
    {
        $value = $result[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function humanTargetType(): string
    {
        if ($this->stringOption('instance') !== null) {
            return 'instance';
        }

        return 'workspace';
    }

    private function humanTarget(): string
    {
        $instance = $this->stringOption('instance');

        if ($instance !== null) {
            return $instance;
        }

        return (string) $this->stringOption('workspace');
    }
}
