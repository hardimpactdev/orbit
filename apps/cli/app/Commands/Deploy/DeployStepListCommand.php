<?php

declare(strict_types=1);

namespace App\Commands\Deploy;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class DeployStepListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'deploy:step-list
        {instance : Instance selector (app.instance)}
        {--json}';

    #[\Override]
    protected $description = 'List deployment pipeline steps for an instance.';

    public function handle(): int
    {
        $instanceSelector = $this->stringArgument('instance');

        if ($instanceSelector === null) {
            return $this->renderFailure(
                'validation_failed',
                'The instance argument is required.',
                ['field' => 'instance'],
            );
        }

        try {
            $response = $this->gatewayGet('/api/deploy/steps', $this->filledQuery([
                'instance' => $instanceSelector,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $steps = $this->stepsFromResponse($response);

        if ($steps === []) {
            $this->line("No deployment steps found for {$instanceSelector}.");

            return self::SUCCESS;
        }

        table(
            headers: ['ID', 'ORDER', 'TITLE', 'COMMAND', 'TIMEOUT'],
            rows: array_map(fn (array $step): array => [
                $this->stepString($step, 'id'),
                $this->stepString($step, 'order'),
                $this->stepString($step, 'title'),
                $this->stepString($step, 'command'),
                $this->stepString($step, 'timeout_seconds'),
            ], $steps),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function stepsFromResponse(array $response): array
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
    private function stepString(array $step, string $key): string
    {
        $value = $step[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
