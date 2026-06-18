<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\WithStepTree;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class NodePermissionsCommand extends GatewayCommand
{
    use WithStepTree;

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

        $isReadMode = $modeCount === 0;

        if ($this->wantsJson()) {
            try {
                $response = $this->applyPermissions($payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        if ($isReadMode) {
            return $this->renderRead($consumingNode, $servingNode, $payload);
        }

        return $this->renderMutationTree($consumingNode, $servingNode, $payload);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function renderRead(string $consumingNode, string $servingNode, array $payload): int
    {
        try {
            $response = $this->applyPermissions($payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $permissions = $this->permissions($response);

        $this->line("Permissions for '{$consumingNode}' on '{$servingNode}': ".implode(', ', $permissions));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function renderMutationTree(string $consumingNode, string $servingNode, array $payload): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Updating Node Permissions',
            [
                ['label' => 'Validate request', 'doneLabel' => 'Validated request'],
                ['label' => 'Apply permission change', 'doneLabel' => 'Applied permission change'],
            ],
            work: function () use ($payload, &$response): array {
                return $response = $this->applyPermissionsForHuman($payload);
            },
            doneFooter: "Permissions for '{$consumingNode}' on '{$servingNode}' updated",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->line('  Permissions: '.implode(', ', $this->permissions($response)));

        foreach ($this->warningMessages($response) as $message) {
            $this->line("  {$message}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function applyPermissions(array $payload): array
    {
        return $this->gatewayPost('/api/nodes/permissions', $payload);
    }

    /**
     * Run the permission change inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function applyPermissionsForHuman(array $payload): array
    {
        try {
            return $this->applyPermissions($payload);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function permissions(array $response): array
    {
        $permissions = $this->successData($response)['permissions'] ?? null;

        if (! is_array($permissions)) {
            return [];
        }

        $values = [];

        foreach ($permissions as $permission) {
            if (is_string($permission) && $permission !== '') {
                $values[] = $permission;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function warningMessages(array $response): array
    {
        $warnings = $response['success']['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $messages = [];

        foreach ($warnings as $entry) {
            if (is_array($entry) && is_string($entry['message'] ?? null) && trim($entry['message']) !== '') {
                $messages[] = trim($entry['message']);
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $data = $response['success']['data'] ?? null;

        return is_array($data) ? $data : [];
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
