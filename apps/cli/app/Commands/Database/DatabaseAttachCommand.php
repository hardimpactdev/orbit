<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Exceptions\GatewayApiException;

final class DatabaseAttachCommand extends DatabaseGatewayCommand
{
    #[\Override]
    protected $signature = 'database:attach
        {connection? : Database connection slug}
        {--instance= : Instance selector (app.instance)}
        {--workspace= : Workspace selector}
        {--env-prefix=DB : Environment variable prefix}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Attach a database connection to an instance or workspace target.';

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
            $response = $this->gatewayPost('/api/database-connections/'.rawurlencode($connection).'/targets', $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $slug = $this->connectionSlug($response, $connection);
        [$targetType, $target] = $this->humanTarget();
        $envPrefix = $this->stringOption('env-prefix') ?? 'DB';

        $this->line("Attached database connection '{$slug}' to {$targetType} '{$target}' with prefix '{$envPrefix}'.");

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

    /**
     * Resolve the human-facing target type and selector from the validated
     * scope options. The attach gateway response returns the full connection
     * rather than the specific target just bound, so the descriptor is derived
     * from the same inputs that produced the request.
     *
     * @return array{string, string}
     */
    private function humanTarget(): array
    {
        $instance = $this->stringOption('instance');
        $workspace = $this->stringOption('workspace');

        if ($instance !== null) {
            return ['instance', $instance];
        }

        return ['workspace', (string) $workspace];
    }
}
