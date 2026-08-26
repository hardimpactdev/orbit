<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class AppDevelopmentSetupStepListCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app-development-setup-step:list {app? : App name} {--json : Output JSON}';
    #[\Override]
    protected $description = 'List app development setup defaults.';

    public function handle(): int
    {
        $app = $this->stringArgument('app') ?? $this->appFromOrbitMarker();
        if ($app === null) {
            return $this->failValidation('app', 'App is required.');
        }
        try {
            $response = $this->gatewayGet($this->apiProjectPath($app, '/development-setup-steps'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }
        $data = $this->successData($response);
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $rows = array_values(array_filter($steps, is_array(...)));
        $this->line("Development setup defaults for {$app}:");
        if ($rows === []) {
            $this->line('No development setup defaults found.');

            return self::SUCCESS;
        }
        table(headers: ['ID', 'ORDER', 'COMMAND', 'TIMEOUT'], rows: array_map(static fn (array $step): array => [
            self::field($step, 'id'),
            self::field($step, 'order'),
            self::field($step, 'command'),
            self::field($step, 'timeout_seconds').'s',
        ], $rows));

        return self::SUCCESS;
    }

    private static function field(array $step, string $key): string
    {
        if (! is_scalar($step[$key] ?? null) || (string) $step[$key] === '') {
            return '—';
        }

        return (string) $step[$key];
    }
}
