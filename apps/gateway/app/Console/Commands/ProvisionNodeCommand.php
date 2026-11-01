<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

final class ProvisionNodeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-provision
        {name : Node name}
        {host : Public SSH host}
        {--ssh-port=22 : Public SSH port}
        {--user= : Bootstrap SSH user; defaults to root for a new node and to the managed user for an existing node}
        {--orbit-user= : Managed Orbit user}
        {--architecture= : Node machine architecture; defaults to the architecture observed on the machine and must match it when given}
        {--tld= : Unique development TLD for app-dev}
        {--role=* : Initial role assignment}
        {--cluster= : Cluster ID}
        {--wireguard-ip= : Stable WireGuard IP}
        {--lan-ip= : Cluster-local LAN IP}
        {--wireguard-endpoint= : Per-node WireGuard endpoint override}
        {--dns-server= : Per-node DNS server override}
        {--host-key-fingerprint= : Expected first-contact SSH SHA256 fingerprint}';

    #[\Override]
    protected $description = 'Admit native Node enrollment from the Gateway.';

    public function handle(ProvisionNodeAction $action): int
    {
        $name = $this->stringArgument('name');
        $host = $this->stringArgument('host');
        $sshPort = filter_var($this->option('ssh-port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $user = $this->stringOption('user');
        $roles = $this->roles();
        $wireguardInput = $this->wireguardIp();
        $clusterId = $this->positiveIntegerOption('cluster');
        $lanIp = $this->stringOption('lan-ip');

        if (
            $name === null
            || $host === null
            || $sshPort === false
            || $roles === null
            || ! $wireguardInput['valid']
            || $this->stringOption('cluster') !== null
            && $clusterId === null
            || $lanIp !== null
            && filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        ) {
            $this->error('Node provisioning arguments are invalid.');

            return self::FAILURE;
        }

        try {
            $operation = $action->start(new ProvisionNodeData(
                name: $name,
                publicSshHost: $host,
                roles: $roles,
                publicSshPort: $sshPort,
                user: $user,
                orbitUser: $this->stringOption('orbit-user'),
                wireguardIp: $wireguardInput['value'],
                wireguardEndpointOverride: $this->stringOption('wireguard-endpoint'),
                dnsServerOverride: $this->stringOption('dns-server'),
                expectedSshHostFingerprint: $this->stringOption('host-key-fingerprint'),
                platform: 'linux',
                architecture: $this->stringOption('architecture'),
                tld: $this->stringOption('tld'),
                clusterProvided: $clusterId !== null,
                clusterId: $clusterId,
                lanIpProvided: $lanIp !== null,
                lanIp: $lanIp,
            ));
        } catch (ResourceOperationException $exception) {
            $this->error("Node enrollment was not admitted [{$exception->errorCode}].");

            return self::FAILURE;
        }

        $this->info("Node enrollment Operation [{$operation->id}] is {$operation->status->value}.");

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

    /** @return array{valid: bool, value: ?string} */
    private function wireguardIp(): array
    {
        return [
            'value' => $this->stringOption('wireguard-ip'),
            'valid' => true,
        ];
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = $this->stringOption($name);

        if ($value === null || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : null;
    }

    /**
     * @return list<RoleName>|null
     */
    private function roles(): ?array
    {
        $values = $this->input->getOption('role');

        if (! is_array($values)) {
            return null;
        }

        $roles = [];

        foreach ($values as $value) {
            if (! is_string($value) || RoleName::tryFrom($value) === null) {
                return null;
            }

            $roles[] = RoleName::from($value);
        }

        return $roles;
    }
}
