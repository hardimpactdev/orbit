<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppCodexCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:codex
        {action? : Codex App action: add, remove, or list}
        {app? : App name or hostname}
        {--node= : Target node running Codex App}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Register app projects in Codex App on a target node.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');
        $app = $this->stringArgument('app');
        $node = $this->stringOption('node');

        if ($action === null || ! in_array($action, ['add', 'remove', 'list'], true)) {
            return $this->failValidation('action', 'Action must be add, remove, or list.');
        }

        if ($node === null) {
            return $this->failValidation('node', 'Node is required.');
        }

        if (in_array($action, ['add', 'remove'], true) && $app === null) {
            return $this->failValidation('app', 'App is required.');
        }

        if ($action === 'list' && $app !== null) {
            return $this->failValidation('app', 'App must be omitted when listing Codex App projects.');
        }

        try {
            $response = match ($action) {
                'add' => $this->gatewayPost($this->apiAppPath((string) $app, '/codex'), ['node' => $node]),
                'remove' => $this->gatewayDelete($this->apiAppPath((string) $app, '/codex'), ['node' => $node]),
                'list' => $this->gatewayGet('/api/apps/codex/projects', ['node' => $node]),
            };
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    #[\Override]
    protected function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    #[\Override]
    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
