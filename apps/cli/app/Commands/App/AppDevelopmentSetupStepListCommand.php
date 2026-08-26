<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class AppDevelopmentSetupStepListCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app-development-setup-step:list {app? : App name} {--json : Output JSON}';
    protected $description = 'List app development setup defaults.';

    public function handle(): int
    {
        $app = $this->stringArgument('app') ?? $this->appFromOrbitMarker();
        if ($app === null)
            return $this->failValidation('app', 'App is required.');
        try {
            $response = $this->gatewayGet($this->apiProjectPath($app, '/development-setup-steps'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
        if ($this->wantsJson())
            return $this->renderSuccess($response);
        $steps = $this->successData($response)['steps'] ?? [];
        $rows = is_array($steps) ? array_values(array_filter($steps, is_array(...))) : [];
        $this->line("Development setup defaults for {$app}:");
        table(headers: ['ID', 'ORDER', 'COMMAND', 'TIMEOUT'], rows: array_map(static fn (array $step): array => [
            self::field($step, 'id'),
            self::field($step, 'sort_order') ?: self::field($step, 'order'),
            self::field($step, 'command'),
            (self::field($step, 'timeout_seconds') ?: self::field($step, 'timeout')).'s',
        ], $rows));

        return self::SUCCESS;
    }

    private static function field(array $step, string $key): string
    {
        $value = $step[$key] ?? '';

        return is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
    }
}
