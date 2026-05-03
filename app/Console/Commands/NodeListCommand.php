<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\Gateway\GatewayRequestSender;
use App\Services\Gateway\Requests\ListNodesRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('node:list
    {--role= : Filter by role (gateway|app|control)}
    {--environment= : Filter by environment (development|production)}
    {--json : Output as JSON}')]
#[Description('List nodes registered in the gateway registry')]
class NodeListCommand extends Command
{
    private const array VALID_ROLES = ['gateway', 'app', 'control'];

    private const array VALID_ENVIRONMENTS = ['development', 'production'];

    public function handle(): int
    {
        $role = $this->option('role');
        $environment = $this->option('environment');

        if (is_string($role) && $role !== '') {
            if (! in_array($role, self::VALID_ROLES, true)) {
                return $this->failValidation(
                    field: 'role',
                    value: $role,
                    allowed: self::VALID_ROLES,
                );
            }
        }

        if (is_string($environment) && $environment !== '') {
            if (! in_array($environment, self::VALID_ENVIRONMENTS, true)) {
                return $this->failValidation(
                    field: 'environment',
                    value: $environment,
                    allowed: self::VALID_ENVIRONMENTS,
                );
            }
        }

        try {
            $nodes = $this->fetchNodes(
                role: is_string($role) && $role !== '' ? $role : null,
                environment: is_string($environment) && $environment !== '' ? $environment : null,
            );
        } catch (RuntimeException $e) {
            return $this->failForwarding($e->getMessage());
        }

        $payload = ['nodes' => $nodes];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->renderHuman($nodes);

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchNodes(?string $role, ?string $environment): array
    {
        if ($this->isGatewayCaller()) {
            return $this->fetchLocalNodes($role, $environment);
        }

        $response = GatewayRequestSender::make()->send(new ListNodesRequest(
            role: $role,
            environment: $environment,
        ));

        if (! $response->isSuccess()) {
            throw new RuntimeException($response->errorMessage() ?? 'Gateway request failed.');
        }

        $data = $response->data();

        return $data['nodes'] ?? [];
    }

    private function isGatewayCaller(): bool
    {
        return $this->callerRole() === 'gateway';
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLocalNodes(?string $role, ?string $environment): array
    {
        $query = Node::query()
            ->orderBy('role')
            ->orderBy('name');

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($environment !== null) {
            $query->where('environment', $environment);
        }

        return $query->get()->map(fn (Node $node): array => [
            'name' => $node->name,
            'role' => $node->role,
            'environment' => $node->role === 'app' ? $node->environment : null,
            'platform' => $node->platform ?? 'unknown',
            'status' => $node->status,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function renderHuman(array $nodes): void
    {
        if ($nodes === []) {
            $this->line('No nodes found.');

            return;
        }

        $this->table(
            ['ROLE', 'NAME', 'ENVIRONMENT', 'PLATFORM', 'STATUS'],
            array_map(fn (array $node): array => [
                $node['role'],
                $node['name'],
                $node['environment'] ?? '—',
                $node['platform'],
                $node['status'],
            ], $nodes),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function failValidation(string $field, string $value, array $allowed): int
    {
        $message = "Invalid value for --{$field}: '{$value}'. Allowed values: ".implode(', ', $allowed).'.';

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                    'meta' => [
                        'field' => $field,
                        'value' => $value,
                        'allowed' => $allowed,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function failForwarding(string $message): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway connection is required to list nodes.',
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error('Gateway connection is required to list nodes.');

        return self::FAILURE;
    }
}
