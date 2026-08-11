<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Support\GatewayActionResult;
use Orbit\Core\Nodes\NodeTld;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class LocalGatewayNodeConverger
{
    public function __construct(
        private NodeRoleAssignments $roleAssignments,
    ) {}

    public function converge(string $name, NodeCreationInput $input): GatewayActionResult
    {
        $tld = $input->stringOption('tld');

        if ($tld === null || ! NodeTld::isValid($tld)) {
            return $this->validationFailed('tld', 'Every node requires an explicit valid non-reserved TLD.');
        }

        $host = $input->stringOption('host');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        if (! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $gateway = $this->roleAssignments
            ->activeGatewayNodeQuery()
            ->where('name', $name)
            ->first();

        if (
            ! $gateway instanceof Node
            || ! $this->gatewayHostMatches($gateway, $host)
            || $gateway->tld !== $tld
        ) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: 'Existing gateway is incompatible with the requested host or identity.',
                meta: ['name' => $name, 'host' => $host],
            );
        }

        return GatewayActionResult::success($this->convergencePayload($gateway, $host));
    }

    private function gatewayHostMatches(Node $gateway, string $host): bool
    {
        return $gateway->host === $host || $gateway->gateway_endpoint === $host;
    }

    /** @return array<string, mixed> */
    private function convergencePayload(Node $gateway, string $host): array
    {
        return [
            'result' => ['action' => 'converged'],
            'node' => [
                'name' => $gateway->name,
                'tld' => $gateway->tld,
                'platform' => $gateway->platform ?? 'unknown',
                'addresses' => [
                    'wireguard' => $gateway->wireguard_address,
                    'gateway_endpoint' => $gateway->gateway_endpoint ?? $gateway->host,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'none',
                'host' => $host,
                'status' => 'already_provisioned',
            ],
            'next_steps' => [],
        ];
    }

    private function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($host, '.') || strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        return array_all(
            explode('.', trim($host, '.')),
            fn ($label) => ! (
                $label === ''
                || strlen((string) $label) > 63
                || ! preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/', (string) $label)
            ),
        );
    }

    private function validationFailed(string $field, string $message): GatewayActionResult
    {
        return GatewayActionResult::error('validation_failed', $message, ['field' => $field]);
    }
}
