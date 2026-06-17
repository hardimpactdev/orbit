<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class DeployHistoryCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'deploy:history
        {app : Production app name or domain}
        {--limit= : Number of runs to return (default 50, hard cap 500)}
        {--json}';

    #[\Override]
    protected $description = 'List deployment runs for a production app.';

    public function handle(): int
    {
        $app = $this->stringArgument('app');

        if ($app === null) {
            return $this->renderFailure(
                'validation_failed',
                'The app argument is required.',
                ['field' => 'app'],
            );
        }

        try {
            $response = $this->gatewayGet('/api/deploy/history', $this->filledQuery([
                'app' => $app,
                'limit' => $this->stringOption('limit'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $runs = $this->runsFromResponse($response);

        if ($runs === []) {
            $this->line("No deployment history found for {$app}.");

            return self::SUCCESS;
        }

        $this->line("Deployment History: {$app}");
        $this->newLine();

        table(
            headers: ['ID', 'STATUS', 'EXIT', 'STARTED'],
            rows: array_map(fn (array $run): array => [
                $this->runString($run, 'id'),
                $this->runString($run, 'status'),
                $this->runString($run, 'exit_code'),
                $this->runString($run, 'started_at'),
            ], $runs),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function runsFromResponse(array $response): array
    {
        $runs = $response['success']['data']['runs'] ?? null;

        if (! is_array($runs)) {
            return [];
        }

        return array_values(array_filter($runs, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function runString(array $run, string $key): string
    {
        $value = $run[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
