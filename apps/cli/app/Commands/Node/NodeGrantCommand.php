<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class NodeGrantCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:grant
        {consuming_node? : Name of the node requesting access}
        {serving_node? : Name of the node providing access}
        {--preset= : Permission preset to apply}
        {--permissions= : Comma-separated list of permissions}
        {--force : Confirm gateway-admin grant without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Grant one node access to another.';

    public function handle(): int
    {
        $consumingNode = $this->stringArgument('consuming_node');
        $servingNode = $this->stringArgument('serving_node');

        if ($consumingNode === null) {
            return $this->renderFailure('validation_failed', 'Consuming node is required.', ['field' => 'consuming_node']);
        }

        if ($servingNode === null) {
            return $this->renderFailure('validation_failed', 'Serving node is required.', ['field' => 'serving_node']);
        }

        $payload = array_filter([
            'consuming_node' => $consumingNode,
            'serving_node' => $servingNode,
            'preset' => $this->stringOption('preset'),
            'permissions' => $this->stringOption('permissions'),
            'force' => (bool) $this->option('force'),
        ], fn (mixed $value): bool => $value !== null);

        try {
            $response = $this->gatewayPost('/api/nodes/grant', $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderGrant($consumingNode, $servingNode, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderGrant(string $consumingNode, string $servingNode, array $response): int
    {
        $data = $this->successData($response);
        $permissions = $this->permissions($data);

        if (($data['already_granted'] ?? null) === true) {
            $this->line("'{$consumingNode}' already has access to '{$servingNode}'");
            $this->line('Permissions: '.implode(', ', $permissions));
            $this->line("Run `orbit node:permissions {$consumingNode} {$servingNode}` to edit this grant.");
        } else {
            $this->line("Granted '{$consumingNode}' access to '{$servingNode}'");
            $this->line('Permissions: '.implode(', ', $permissions));
        }

        $warnings = $this->warningMessages($response);

        if ($warnings !== []) {
            $this->line('Warnings:');

            foreach ($warnings as $message) {
                $this->line("- {$message}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function permissions(array $data): array
    {
        $permissions = $data['permissions'] ?? null;

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
