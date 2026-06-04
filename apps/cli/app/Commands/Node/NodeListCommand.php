<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class NodeListCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:list
        {--role= : Filter by role}
        {--json}';

    #[\Override]
    protected $description = 'List nodes registered in the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/nodes', array_filter([
                'role' => $this->option('role'),
            ], fn (mixed $v): bool => $v !== null));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $nodes = $this->nodesFromGatewayResponse($response);

        if ($nodes === []) {
            $this->line('No nodes found.');

            return self::SUCCESS;
        }

        table(
            headers: ['ROLES', 'NAME', 'PLATFORM', 'STATUS'],
            rows: array_map(fn (array $node): array => [
                $this->humanRoles($node['roles'] ?? []),
                $this->nodeString($node, 'name'),
                $this->nodeString($node, 'platform'),
                $this->nodeString($node, 'status'),
            ], $nodes),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function nodesFromGatewayResponse(array $response): array
    {
        $nodes = $response['success']['data']['nodes'] ?? null;

        if (! is_array($nodes)) {
            return [];
        }

        return array_values(array_filter($nodes, is_array(...)));
    }

    private function humanRoles(mixed $roles): string
    {
        if (! is_array($roles) || $roles === []) {
            return '—';
        }

        $labels = [];

        foreach ($roles as $role) {
            if (! is_array($role) || ! is_string($role['role'] ?? null) || $role['role'] === '') {
                continue;
            }

            $status = is_string($role['status'] ?? null) ? $role['status'] : 'active';

            $labels[] = $status === 'active'
                ? $role['role']
                : "{$role['role']} ({$status})";
        }

        return $labels === [] ? '—' : implode(', ', $labels);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeString(array $node, string $key): string
    {
        $value = $node[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return 'unknown';
    }
}
