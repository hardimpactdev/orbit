<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\OrbitHostInstaller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('node:new
    {name? : Registry name for the node}
    {--role= : Node role: gateway, control, or app}
    {--host= : SSH/bootstrap endpoint for gateway or app nodes}
    {--control-name= : Initiating control-node name for first-gateway bootstrap}
    {--environment= : App-node environment: development or production}
    {--tld= : Development app-node TLD}
    {--ssh-user=root : SSH user for provisioning}
    {--json : Output JSON}')]
#[Description('Create or provision a node in the Orbit fleet')]
class NodeNewCommand extends Command
{
    private const string DEFAULT_RUNTIME_USER = 'orbit';

    public function handle(OrbitHostInstaller $installer): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
            );
        }

        $name = $this->stringArgument('name');
        $role = $this->stringOption('role');

        if ($name === null) {
            return $this->validationFailed('name', 'Node name is required.');
        }

        if (! $this->isValidNodeName($name)) {
            return $this->validationFailed('name', 'Node name must be a valid node name.');
        }

        if ($role === null) {
            return $this->validationFailed('role', 'Node role is required.');
        }

        if (! in_array($role, ['gateway', 'app', 'control'], true)) {
            return $this->validationFailed('role', 'Node role must be one of gateway, app, or control.');
        }

        $gatewayConfigured = Node::query()
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->exists();

        if ($callerRole === 'control' && ! $gatewayConfigured && $role !== 'gateway') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required before creating app or control nodes.',
                meta: ['requested_role' => $role],
            );
        }

        if ($role !== 'gateway') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway forwarding is required before this node role can be created.',
                meta: ['requested_role' => $role],
            );
        }

        if ($gatewayConfigured) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway forwarding is required before gateway convergence can run.',
                meta: ['requested_role' => $role],
            );
        }

        return $this->bootstrapFirstGateway($installer, $name);
    }

    private function bootstrapFirstGateway(OrbitHostInstaller $installer, string $name): int
    {
        $host = $this->stringOption('host');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        $controlName = $this->stringOption('control-name') ?? $this->defaultControlName();

        if ($controlName === null || ! $this->isValidNodeName($controlName)) {
            return $this->validationFailed('control_name', 'Control node name must be a valid node name.');
        }

        if ($controlName === $name) {
            return $this->validationFailed('control_name', 'Control node name must be different from gateway node name.');
        }

        $sshUser = $this->stringOption('ssh-user') ?? 'root';
        $runtimeUser = self::DEFAULT_RUNTIME_USER;
        $gatewayAddress = '10.6.0.2';
        $controlAddress = $this->nextWireguardAddress(excluding: [$gatewayAddress]);

        $installation = $installer->install($host, $sshUser, 'gateway', $runtimeUser);

        if (! $installation->successful) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway host '{$host}' could not complete Orbit installation.",
                meta: [
                    'host' => $host,
                    'step' => 'install_orbit',
                    'error' => trim($installation->errorOutput) ?: null,
                ],
            );
        }

        DB::transaction(function () use ($name, $host, $sshUser, $runtimeUser, $controlName, $gatewayAddress, $controlAddress): void {
            Node::query()->where('is_local', true)->update(['is_local' => false]);

            Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'role' => 'gateway',
                    'environment' => null,
                    'tld' => null,
                    'platform' => 'unknown',
                    'host' => $host,
                    'wireguard_address' => $gatewayAddress,
                    'gateway_endpoint' => $host,
                    'ssh_user' => $sshUser,
                    'user' => $runtimeUser,
                    'orbit_path' => "/home/{$runtimeUser}/orbit",
                    'status' => 'active',
                    'is_local' => false,
                ],
            );

            Node::query()->updateOrCreate(
                ['name' => $controlName],
                [
                    'role' => 'control',
                    'environment' => null,
                    'tld' => null,
                    'platform' => 'unknown',
                    'host' => '127.0.0.1',
                    'wireguard_address' => $controlAddress,
                    'gateway_endpoint' => $host,
                    'ssh_user' => get_current_user(),
                    'orbit_path' => base_path(),
                    'status' => 'active',
                    'is_local' => true,
                ],
            );
        });

        $payload = [
            'result' => [
                'action' => 'created',
            ],
            'node' => [
                'name' => $name,
                'role' => 'gateway',
                'environment' => null,
                'tld' => null,
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => $gatewayAddress,
                    'gateway_endpoint' => $host,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'ssh',
                'host' => $host,
                'status' => 'complete',
            ],
            'local_control_node' => [
                'name' => $controlName,
                'role' => 'control',
                'environment' => null,
                'tld' => null,
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => $controlAddress,
                ],
                'status' => 'active',
            ],
            'local_onboarding' => [
                'wireguard' => 'pending',
                'gateway_trust' => 'pending',
                'gateway_config' => 'stored',
                'gateway_api' => 'pending',
            ],
            'next_steps' => [],
        ];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info("Created gateway node {$name}.");
        $this->line("Endpoint: {$host}");
        $this->line("Control node: {$controlName}");

        return self::SUCCESS;
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        return is_string($localRole) && $localRole !== '' ? $localRole : 'control';
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function defaultControlName(): ?string
    {
        $hostname = gethostname();

        if (! is_string($hostname) || $hostname === '') {
            return null;
        }

        $short = explode('.', $hostname)[0] ?? '';
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $short));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : null;
    }

    private function isValidNodeName(string $name): bool
    {
        return (bool) preg_match('/^[a-z](?:[a-z0-9-]*[a-z0-9])?$/', $name);
    }

    /**
     * @param  array<int, string>  $excluding
     */
    private function nextWireguardAddress(array $excluding = []): string
    {
        $used = Node::query()
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->all();

        $used = array_merge($used, $excluding);

        for ($octet = 3; $octet <= 254; $octet++) {
            $candidate = "10.6.0.{$octet}";

            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No available WireGuard addresses remain in 10.6.0.0/24.');
    }

    private function validationFailed(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
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
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => $meta,
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
}
