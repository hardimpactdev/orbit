<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppMountCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'instance:mount
        {action? : Action to perform (list|add|remove)}
        {instance? : Instance selector (app.instance or hostname)}
        {source? : Host source path for add}
        {target? : Container target path}
        {--read-only : Mount read-only (default)}
        {--read-write : Mount read-write}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'List or change additional Docker runtime mounts for an instance.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');
        $selector = $this->stringArgument('instance');

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
            return $this->failValidation('instance', 'Instance is required.');
        }

        return match ($action) {
            'list' => $this->listMounts($selector),
            'add' => $this->addMount($selector),
            'remove' => $this->removeMount($selector),
        };
    }

    private function listMounts(string $selector): int
    {
        try {
            $response = $this->gatewayGet($this->apiInstancePath($selector, '/mounts'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function addMount(string $selector): int
    {
        if (! $this->hasInstanceSelector($selector)) {
            return $this->dottedInstanceRequired();
        }

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
            $response = $this->gatewayPost($this->apiInstancePath($selector, '/mounts'), [
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
        if (! $this->hasInstanceSelector($selector)) {
            return $this->dottedInstanceRequired();
        }

        $target = $this->stringArgument('target') ?? $this->stringArgument('source');

        if ($target === null) {
            return $this->failValidation('target', 'Target path is required.');
        }

        try {
            $response = $this->gatewayDelete($this->apiInstancePath($selector, '/mounts'), [
                'target' => $target,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function hasInstanceSelector(string $selector): bool
    {
        return substr_count(haystack: $selector, needle: '.') === 1;
    }

    private function dottedInstanceRequired(): int
    {
        return $this->renderFailure(
            'validation_failed',
            'Runtime mounts can only be changed with a dotted instance selector such as hauser.nmbp.',
            ['field' => 'instance', 'reason' => 'dotted_instance_required'],
        );
    }
}
