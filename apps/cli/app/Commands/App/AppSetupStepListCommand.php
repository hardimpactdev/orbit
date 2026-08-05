<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class AppSetupStepListCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'instance-setup-step:list
        {instance? : Instance selector (app.instance or hostname)}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'List instance setup steps.';

    public function handle(): int
    {
        $app = $this->resolveInstanceSelector();

        if ($app === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        try {
            $response = $this->gatewayGet($this->apiInstancePath($app, '/setup-steps'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $steps = $this->stepsFromGatewayResponse($response);

        if ($steps === []) {
            $this->line("No setup steps defined for {$app}.");

            return self::SUCCESS;
        }

        $this->line("Setup steps for {$app}:");

        table(
            headers: ['ID', 'ORDER', 'COMMAND', 'TIMEOUT'],
            rows: array_map(fn (array $step): array => [
                $this->stepString($step, 'id'),
                $this->stepString($step, 'order'),
                $this->stepString($step, 'command'),
                $this->stepTimeout($step),
            ], $steps),
        );

        return self::SUCCESS;
    }

    private function resolveInstanceSelector(): ?string
    {
        return $this->stringArgument('instance') ?? $this->instanceFromOrbitMarker();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function stepsFromGatewayResponse(array $response): array
    {
        $steps = $response['success']['data']['steps'] ?? null;

        if (! is_array($steps)) {
            return [];
        }

        return array_values(array_filter($steps, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepTimeout(array $step): string
    {
        $timeout = $step['timeout_seconds'] ?? null;

        if (is_scalar($timeout) && (string) $timeout !== '') {
            return "{$timeout}s";
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepString(array $step, string $key): string
    {
        $value = $step[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
