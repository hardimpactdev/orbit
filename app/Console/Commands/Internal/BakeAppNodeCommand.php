<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Services\Nodes\NodeRegistryWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:internal:bake-app-node
    {name : App node name}
    {--role=app : Node role}
    {--environment= : App-node environment: development or production}
    {--host= : App-node host address}
    {--wireguard-address= : App-node WireGuard address}
    {--gateway-endpoint= : Gateway endpoint address}
    {--ssh-user=orbit : SSH user}
    {--user=orbit : Runtime user}
    {--tld= : Development app-node TLD}')]
#[Description('Bake an app-node registry row for prepared E2E topology images')]
class BakeAppNodeCommand extends Command
{
    protected $hidden = true;

    public function handle(NodeRegistryWriter $registryWriter): int
    {
        $name = $this->stringArgument('name');
        $role = $this->stringOption('role') ?? 'app';
        $environment = $this->stringOption('environment');
        $host = $this->stringOption('host');
        $wireguardAddress = $this->stringOption('wireguard-address');
        $gatewayEndpoint = $this->stringOption('gateway-endpoint');
        $sshUser = $this->stringOption('ssh-user') ?? 'orbit';
        $user = $this->stringOption('user') ?? 'orbit';
        $tld = $this->stringOption('tld');

        if ($name === null || $environment === null || $host === null || $wireguardAddress === null) {
            throw new RuntimeException('Name, environment, host, and wireguard-address are required.');
        }

        if ($role !== 'app') {
            throw new RuntimeException('Only app nodes can be baked with this command.');
        }

        $registryWriter->writeAppNode(
            name: $name,
            environment: $environment,
            tld: $tld,
            host: $host,
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            sshUser: $sshUser,
            user: $user,
        );

        return self::SUCCESS;
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
}
