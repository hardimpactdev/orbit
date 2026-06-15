<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class NodeListCommand extends GatewayCommand
{
    private const string ActiveStatusIndicator = "\e[32m●\e[0m";

    private const string InactiveStatusIndicator = "\e[31m●\e[0m";

    private const array RoleSortOrder = [
        'gateway' => 0,
        'vpn' => 1,
        'router' => 2,
        'app-dev' => 3,
        'app-prod' => 4,
        'database' => 5,
        'agent' => 6,
        'ingress' => 7,
        'websocket' => 8,
        's3' => 9,
    ];

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
            headers: ['', 'NAME', 'PEER IP', 'PLATFORM', 'ROLES'],
            rows: array_map(fn (array $node): array => [
                $this->statusIndicator($node),
                $this->nodeString($node, 'name'),
                $this->peerIp($node),
                $this->humanPlatform($node),
                $this->humanRoles($node['roles'] ?? []),
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

        usort($labels, fn (string $first, string $second): int => [
            $this->roleSortIndex($first),
            $first,
        ] <=> [
            $this->roleSortIndex($second),
            $second,
        ]);

        return $labels === [] ? '—' : implode(', ', $labels);
    }

    private function roleSortIndex(string $label): int
    {
        $role = trim((string) preg_replace('/\s+\(.+\)$/', '', $label));

        return self::RoleSortOrder[$role] ?? 999;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function statusIndicator(array $node): string
    {
        return ($node['status'] ?? null) === 'active'
            ? self::ActiveStatusIndicator
            : self::InactiveStatusIndicator;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function humanPlatform(array $node): string
    {
        $platform = $this->nodeString($node, 'platform');

        if ($platform === 'unknown') {
            return $platform;
        }

        return explode('_', $platform, 2)[0] ?: 'unknown';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function peerIp(array $node): string
    {
        $addresses = $node['addresses'] ?? null;
        $wireguardAddress = is_array($addresses) ? ($addresses['wireguard'] ?? null) : null;

        if (is_scalar($wireguardAddress) && (string) $wireguardAddress !== '') {
            return (string) $wireguardAddress;
        }

        $legacyWireguardAddress = $node['wireguard_address'] ?? null;

        if (is_scalar($legacyWireguardAddress) && (string) $legacyWireguardAddress !== '') {
            return (string) $legacyWireguardAddress;
        }

        return 'unknown';
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
