<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalFirewallRuleAction
{
    private const array ACTIONS = ['apply', 'delete'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(array $payload): array
    {
        $action = $this->action($payload['action'] ?? null);
        $shape = LocalFirewallRuleShape::from($payload['shape'] ?? null);

        $command = $action === 'apply'
            ? $this->applyCommand($shape)
            : $this->deleteCommand($shape);
        $result = $this->runProcess($command);

        if (! $result->isSuccessful()) {
            throw $this->failure($action, $shape, $result);
        }

        $reload = $this->runProcess(['sudo', 'ufw', 'reload']);

        if (! $reload->isSuccessful()) {
            throw $this->failure('reload', $shape, $reload);
        }

        return [
            'action' => $action,
            'changed' => true,
            'port' => $shape->port,
            'protocol' => $shape->protocol,
        ];
    }

    /**
     * @return list<string>
     */
    private function applyCommand(LocalFirewallRuleShape $shape): array
    {
        return [
            'sudo',
            'ufw',
            $shape->action,
            $shape->direction === 'outgoing' ? 'out' : 'in',
            ...$this->interfaceArguments($shape),
            'from',
            $this->sourceEndpointForFamily($shape, $shape->source),
            'to',
            $shape->destination === null
                ? $this->anyForFamily($shape)
                : $this->destinationEndpointForFamily($shape, $shape->destination),
            'port',
            $shape->port,
            'proto',
            $shape->protocol,
            ...$this->commentArguments($shape),
        ];
    }

    /**
     * @return list<string>
     */
    private function deleteCommand(LocalFirewallRuleShape $shape): array
    {
        return [
            'sudo',
            'ufw',
            'delete',
            $shape->action,
            $shape->direction === 'outgoing' ? 'out' : 'in',
            ...$this->interfaceArguments($shape),
            'from',
            $shape->source,
            'to',
            $shape->destination ?? 'any',
            'port',
            $shape->port,
            'proto',
            $shape->protocol,
        ];
    }

    /**
     * @return list<string>
     */
    private function interfaceArguments(LocalFirewallRuleShape $shape): array
    {
        if ($shape->interface === null) {
            return [];
        }

        return ['on', $this->resolveInterface($shape->interface)];
    }

    private function resolveInterface(string $interface): string
    {
        $command = $interface === 'wireguard'
            ? ['ip', '-o', 'link', 'show', 'type', 'wireguard']
            : ['ip', 'route', 'show', 'default', '0.0.0.0/0'];
        $result = $this->runProcess($command);

        if (! $result->isSuccessful()) {
            throw new LocalFirewallRuleFailure(
                errorCode: 'firewall_rule.interface_unavailable',
                message: 'Firewall interface could not be resolved.',
                meta: [
                    'interface' => $interface,
                    'exit_code' => $result->getExitCode(),
                    'stderr' => trim($result->getErrorOutput()),
                ],
            );
        }

        $name = $interface === 'wireguard'
            ? $this->wireguardInterface($result->getOutput())
            : $this->publicInterface($result->getOutput());

        if ($name !== null) {
            return $name;
        }

        throw new LocalFirewallRuleFailure(
            errorCode: 'firewall_rule.interface_unavailable',
            message: 'Firewall interface could not be resolved.',
            meta: ['interface' => $interface],
        );
    }

    private function wireguardInterface(string $output): ?string
    {
        $matches = [];

        if (preg_match("/^\\d+:\\s+([^:@]+)[@:]/m", $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function publicInterface(string $output): ?string
    {
        $matches = [];

        if (preg_match('/\\bdev\\s+(\\S+)/', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function commentArguments(LocalFirewallRuleShape $shape): array
    {
        if ($shape->reason === null) {
            return [];
        }

        return ['comment', $shape->reason];
    }

    private function sourceEndpointForFamily(LocalFirewallRuleShape $shape, string $endpoint): string
    {
        return $this->endpointForFamily($shape, $endpoint, anyFallback: 'any');
    }

    private function destinationEndpointForFamily(LocalFirewallRuleShape $shape, string $endpoint): string
    {
        return $this->endpointForFamily($shape, $endpoint, anyFallback: 'any');
    }

    private function endpointForFamily(LocalFirewallRuleShape $shape, string $endpoint, string $anyFallback): string
    {
        if ($endpoint !== 'any') {
            return $endpoint;
        }

        if ($shape->addressFamily === 'v4') {
            return '0.0.0.0/0';
        }

        if ($shape->addressFamily === 'v6') {
            return '::/0';
        }

        return $anyFallback;
    }

    private function anyForFamily(LocalFirewallRuleShape $shape): string
    {
        if ($shape->addressFamily === 'v4') {
            return '0.0.0.0/0';
        }

        if ($shape->addressFamily === 'v6') {
            return '::/0';
        }

        return 'any';
    }

    private function action(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::ACTIONS, strict: true)) {
            return $value;
        }

        throw new LocalFirewallRuleFailure(
            errorCode: 'validation_failed',
            message: 'Firewall rule action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    private function failure(
        string $action,
        LocalFirewallRuleShape $shape,
        Process $result,
    ): LocalFirewallRuleFailure {
        return new LocalFirewallRuleFailure(
            errorCode: "firewall_rule.{$action}_failed",
            message: "Firewall rule {$action} failed.",
            meta: [
                'action' => $action,
                'port' => $shape->port,
                'protocol' => $shape->protocol,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }
}
