<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class NodePermissionsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:permissions
        {consuming_node? : Name of the consuming node}
        {serving_node? : Name of the serving node}
        {--preset= : Permission preset to apply}
        {--permissions= : Comma-separated list of permissions}
        {--add= : Comma-separated permissions to add}
        {--remove= : Comma-separated permissions to remove}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Manage node access permissions.';

    public function handle(): int
    {
        $consumingNode = $this->stringArgument('consuming_node');
        $servingNode = $this->stringArgument('serving_node');

        if ($consumingNode === null || $servingNode === null) {
            return $this->renderFailure(
                'validation_failed',
                'Both consuming_node and serving_node are required.',
                ['fields' => ['consuming_node', 'serving_node']],
            );
        }

        $modeCount = 0;
        foreach (['preset', 'permissions', 'add', 'remove'] as $option) {
            if ($this->stringOption($option) !== null) {
                $modeCount++;
            }
        }

        if ($modeCount > 1) {
            return $this->renderFailure('validation_failed', 'Use only one of --preset, --permissions, --add, or --remove.');
        }

        $payload = array_filter([
            'consuming_node' => $consumingNode,
            'serving_node' => $servingNode,
            'preset' => $this->stringOption('preset'),
            'permissions' => $this->stringOption('permissions'),
            'add' => $this->stringOption('add'),
            'remove' => $this->stringOption('remove'),
        ], fn (?string $value): bool => $value !== null);

        try {
            $response = $this->gatewayPost('/api/nodes/permissions', $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
