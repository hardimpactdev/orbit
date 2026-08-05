<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppWorkerCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'instance:worker
        {action? : Action to perform (show|enable|disable)}
        {instance? : Instance selector (app.instance or hostname)}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Inspect or change FrankenPHP worker mode for an instance.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');
        $selector = $this->stringArgument('instance');

        if (! in_array($action, ['show', 'enable', 'disable'], true)) {
            return $this->renderFailure(
                'validation_failed',
                'Action must be one of: show, enable, disable.',
                ['field' => 'action', 'allowed' => ['show', 'enable', 'disable']],
            );
        }

        if ($selector === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        try {
            $response = $action === 'show'
                ? $this->gatewayGet($this->apiInstancePath($selector, '/worker'))
                : $this->gatewayPost($this->apiInstancePath($selector, "/worker/{$action}"));
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
        $instance = is_string($data['instance'] ?? null) && $data['instance'] !== ''
            ? $data['instance']
            : null;
        $target = $instance !== null ? "{$app}.{$instance}" : $selector;
        $enabled = ($data['worker_enabled'] ?? null) === true;
        $changed = ($data['changed'] ?? null) === true;

        $this->line($this->statusLine($action, $target, $enabled, $changed));

        foreach ($this->workerConfigPairs($data['worker_config'] ?? null) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function statusLine(string $action, string $instance, bool $enabled, bool $changed): string
    {
        if ($action === 'show') {
            $state = $enabled ? 'enabled' : 'disabled';

            return "Instance '{$instance}' worker mode is {$state}.";
        }

        $verb = $action === 'enable' ? 'enabled' : 'disabled';

        if ($changed) {
            return "Instance '{$instance}' worker mode {$verb}.";
        }

        return "Instance '{$instance}' worker mode already {$verb}.";
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
