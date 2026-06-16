<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppMountCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:mount
        {action? : Action to perform (list|add|remove)}
        {app? : App name or hostname}
        {source? : Host source path for add}
        {target? : Container target path}
        {--read-only : Mount read-only (default)}
        {--read-write : Mount read-write}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'List or change additional Docker runtime mounts for an app.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');
        $selector = $this->stringArgument('app');

        if ($action === null) {
            return $this->failValidation('action', 'Action is required.');
        }

        if (! in_array($action, ['list', 'add', 'remove'], true)) {
            return $this->renderFailure(
                'validation_failed',
                'Action must be one of: list, add, remove.',
                ['field' => 'action', 'allowed' => ['list', 'add', 'remove']],
            );
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        if ($action === 'list') {
            return $this->listMounts($selector);
        }

        if ($action === 'add') {
            return $this->addMount($selector);
        }

        return $this->removeMount($selector);
    }

    private function listMounts(string $selector): int
    {
        try {
            $response = $this->gatewayGet($this->apiAppPath($selector, '/mounts'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function addMount(string $selector): int
    {
        $source = $this->stringArgument('source');
        $target = $this->stringArgument('target');

        if ($source === null) {
            return $this->failValidation('source', 'Source path is required.');
        }

        if ($target === null) {
            return $this->failValidation('target', 'Target path is required.');
        }

        if ($this->option('read-only') && $this->option('read-write')) {
            return $this->renderFailure(
                'validation_failed',
                'Choose either --read-only or --read-write.',
                ['field' => 'read_mode'],
            );
        }

        try {
            $response = $this->gatewayPost($this->apiAppPath($selector, '/mounts'), [
                'source' => $source,
                'target' => $target,
                'read_only' => ! (bool) $this->option('read-write'),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function removeMount(string $selector): int
    {
        $target = $this->stringArgument('target') ?? $this->stringArgument('source');

        if ($target === null) {
            return $this->failValidation('target', 'Target path is required.');
        }

        try {
            $response = $this->gatewayDelete($this->apiAppPath($selector, '/mounts'), [
                'target' => $target,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
