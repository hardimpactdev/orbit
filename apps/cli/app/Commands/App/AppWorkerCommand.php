<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppWorkerCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:worker
        {action? : Action to perform (show|enable|disable)}
        {app? : App name or hostname}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Inspect or change FrankenPHP worker mode for an app.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');
        $selector = $this->stringArgument('app');

        if (! in_array($action, ['show', 'enable', 'disable'], true)) {
            return $this->renderFailure(
                'validation_failed',
                'Action must be one of: show, enable, disable.',
                ['field' => 'action', 'allowed' => ['show', 'enable', 'disable']],
            );
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        try {
            $response = $action === 'show'
                ? $this->gatewayGet($this->apiAppPath($selector, '/worker'))
                : $this->gatewayPost($this->apiAppPath($selector, "/worker/{$action}"));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderWorker($action, $selector, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderWorker(string $action, string $selector, array $response): int
    {
        $data = $this->successData($response);
        $app = is_string($data['app'] ?? null) && $data['app'] !== '' ? $data['app'] : $selector;
        $enabled = ($data['worker_enabled'] ?? null) === true;
        $changed = ($data['changed'] ?? null) === true;

        $this->line($this->statusLine($action, $app, $enabled, $changed));

        foreach ($this->workerConfigPairs($data['worker_config'] ?? null) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function statusLine(string $action, string $app, bool $enabled, bool $changed): string
    {
        if ($action === 'show') {
            $state = $enabled ? 'enabled' : 'disabled';

            return "App '{$app}' worker mode is {$state}.";
        }

        $verb = $action === 'enable' ? 'enabled' : 'disabled';

        if ($changed) {
            return "App '{$app}' worker mode {$verb}.";
        }

        return "App '{$app}' worker mode already {$verb}.";
    }

    /**
     * @return list<string>
     */
    private function workerConfigPairs(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }

        $lines = [];

        foreach (['workers', 'max_requests'] as $key) {
            $value = $config[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                $lines[] = "  {$key}: {$value}";
            }
        }

        return $lines;
    }
}
