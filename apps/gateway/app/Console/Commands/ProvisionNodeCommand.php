<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

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
        {--host-key-fingerprint= : Expected first-contact SSH SHA256 fingerprint}
        {--diagnostics : Include bounded, redacted failure details}';

    #[\Override]
    protected $description = 'Provision the first node directly from the gateway.';

    public function handle(ProvisionNodeAction $action): int
    {
        $name = $this->stringArgument('name');
        $host = $this->stringArgument('host');
        $sshPort = $this->option('ssh-port');
        $user = $this->stringOption('user');
        $roles = $this->roles();
        $wireguardInput = $this->wireguardIp();
        $clusterId = $this->positiveIntegerOption('cluster');
        $lanIp = $this->stringOption('lan-ip');

        if (
            $name === null
            || $host === null
            || ! is_numeric($sshPort)
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
            $node = $action->execute(new ProvisionNodeData(
                name: $name,
                publicSshHost: $host,
                roles: $roles,
                publicSshPort: (int) $sshPort,
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
        } catch (NodeProvisioningException $exception) {
            $this->error(
                "Node provisioning failed at step [{$exception->step}] with error [{$exception->errorCode}].",
            );

            if ($this->option('diagnostics')) {
                $tail = $this->commandFailureTail($exception);
                if ($tail !== '') {
                    $this->output->getErrorStyle()->writeln($tail, OutputInterface::OUTPUT_RAW);
                }
            }

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] is {$node->status->value}.");

        return self::SUCCESS;
    }

    private function commandFailureTail(NodeProvisioningException $exception): string
    {
        $messages = [];
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $messages[] = $cause::class.': '.$cause->getMessage();
            if (! $cause instanceof NodeProvisioningException && ! $cause instanceof RuntimeConvergenceException && ! $cause instanceof AgentRunnerFailure) {
                continue;
            }
            $result = $cause->result;
            if ($result === null || trim($result->stdout.$result->stderr) === '') {
                continue;
            }

            // Role-operation wrappers intentionally strip output; diagnostics uses the underlying failure only.
            return $this->redactedTail($result->stdout."\n".$result->stderr);
        }

        return $this->redactedTail(implode("\n", $messages));
    }

    private function redactedTail(string $text): string
    {
        $redacted = new CommandActivityInputSanitizer()->redactText($text);
        $lines = preg_split('/\R/u', mb_scrub(trim($redacted))) ?: [];

        return mb_substr(implode("\n", array_slice($lines, -40)), -4000);
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
