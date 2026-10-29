<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\LinuxUserName;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;

/** Pinned first-contact transport. The request cannot select a connection or an SSH credential. */
final readonly class BootstrapSshOperationTransport implements OperationEffectTransport
{
    private const array Commands = [
        'agent.install', 'agent.status', 'node.bootstrap', 'node.inspect-macos-enrollment',
        'firewall.rules', 'wireguard.prepare-peer-key', 'wireguard.publish-peer',
        'wireguard.commit-peer', 'wireguard.rollback-peer',
    ];

    public function __construct(
        private AdmissionAgentRunner $agents,
        private NodeOperationAgentInstaller $installer,
        private HostKeyScanner $hostKeys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return in_array($run['name'], self::Commands, true) ? self::inputSchema($run['name']) : false;
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $hydratedInput): array
    {
        $name = $run['name'];
        $input = $hydratedInput;
        if (! in_array($name, self::Commands, true)) {
            throw $this->forbidden();
        }
        $command = ValidatedData::object($input['command_input'] ?? []);
        if ($name === 'agent.install') {
            if ($command !== []) {
                throw $this->forbidden();
            }
        } else {
            try {
                AgentSchemas::assertInput($name, $command);
            } catch (LogicException) {
                throw $this->forbidden();
            }
        }

        [$node, $connection, $key] = $this->connection($operation, $run, $input, $input['bootstrap_identity'] ?? null);
        if ($name === 'agent.install') {
            $architecture = $this->agents->architectureOver($node, $connection);
            if ($node->platform === 'linux' && ! in_array($architecture, ['amd64', 'x86_64'], true)) {
                throw new ResourceOperationException('node.architecture_unsupported', 'Managed Linux Nodes require x86_64.', 422);
            }
            if ($node->architecture !== null && $node->architecture !== $architecture) {
                throw new ResourceOperationException('node.architecture_mismatch', 'The bootstrap architecture differs from the admitted Node.', 409);
            }
            $node->architecture = $architecture;
            $changed = $this->installer->installEffect($node, ValidatedData::string($operation->input['agent_pin'] ?? null), $run, $connection);

            return ['installed' => true, 'changed' => $changed, 'architecture' => $architecture,
                'host_type' => $key->type, 'public_key' => $key->value, 'host_key_fingerprint' => $key->fingerprint];
        }

        return $this->agents->runOver($node, $connection, $name, $this->effectful($operation, $run) ? AgentEffectReceipts::input($run, $command) : $command);
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return in_array($run['name'], self::Commands, true) && ! in_array($run['name'], ['agent.status', 'node.inspect-macos-enrollment'], true);
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $identity = app(OperationSecrets::class)->connection($operation, $run, 'bootstrap_identity');
        [$node, $connection, $key] = $this->connection($operation, $run, $input, $identity->value);
        if ($run['name'] === 'agent.install') {
            $architecture = $this->agents->architectureOver($node, $connection);
            if (($node->platform === 'linux' && ! in_array($architecture, ['amd64', 'x86_64'], true))
                || ($node->architecture !== null && $node->architecture !== $architecture)) {
                throw $this->forbidden();
            }
            $node->architecture = $architecture;
        }
        $receipt = $this->installer->effectReceipt($node, ValidatedData::string($operation->input['agent_pin'] ?? null), $run['name'], $run['effect_key'], $connection);
        if ($run['name'] === 'agent.install' && $receipt['state'] === 'succeeded') {
            if (! is_bool($receipt['result']['replaced'] ?? null)) {
                throw new ResourceOperationException('operation.receipt_unproven', 'The installer receipt has no original replacement result.', 409);
            }
            $receipt['result'] = ['installed' => true, 'changed' => ($receipt['result']['replaced'] ?? null) === true,
                'architecture' => $node->architecture, 'host_type' => $key->type, 'public_key' => $key->value, 'host_key_fingerprint' => $key->fingerprint];
        }

        return $receipt;
    }

    /** @param array<string, mixed> $run
     * @param  array<string, mixed>  $input
     * @return array{Node,SshConnection,HostKey}
     */
    private function connection(Operation $operation, array $run, array $input, #[\SensitiveParameter] mixed $identity): array
    {
        if (! in_array($run['name'], self::Commands, true)) {
            throw $this->forbidden();
        }
        $node = Node::query()->findOrFail($operation->node_id);
        $host = $operation->input['bootstrap_host'] ?? null;
        $port = $operation->input['bootstrap_port'] ?? null;
        $fingerprint = $operation->input['bootstrap_fingerprint'] ?? null;
        $user = $operation->input['bootstrap_user'] ?? null;
        if (! is_string($host) || $host === '' || ! is_int($port) || $port < 1 || $port > 65535
            || ! is_string($fingerprint) || preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $fingerprint) !== 1
            || ! is_string($user) || ! LinuxUserName::isValid($user)) {
            throw $this->forbidden();
        }
        foreach (['bootstrap_host' => $host, 'bootstrap_port' => $port, 'bootstrap_fingerprint' => $fingerprint, 'bootstrap_user' => $user] as $field => $expected) {
            if (($input[$field] ?? null) !== $expected) {
                throw $this->forbidden();
            }
        }
        $key = $this->hostKeys->scan($host, $port, $node->sshJumpNode);
        if (! hash_equals($fingerprint, $key->fingerprint)
            || ($node->ssh_host_fingerprint !== null && ! hash_equals($node->ssh_host_fingerprint, $key->fingerprint))) {
            throw new ResourceOperationException('node.ssh_host_key_mismatch', 'The bootstrap host does not match the admitted SSH fingerprint.', 409);
        }
        $this->knownHosts->put($host, $port, $key);
        if (! is_string($identity) || $identity === '') {
            throw $this->forbidden();
        }
        $connection = new SshConnection(host: $host, user: $user, port: $port,
            identityFile: $identity, knownHostsFile: $this->knownHosts->path(),
            proxyJump: NodeSshJump::connection($node, $identity, $this->knownHosts->path()),
            hostIdentity: $fingerprint, shareConnection: false);

        return [$node, $connection, $key];
    }

    /** @return array<string, mixed> */
    public static function inputSchema(string $name): array
    {
        $schemas = ValidatedData::object(json_decode((string) file_get_contents(AgentSchemas::Path), true, flags: JSON_THROW_ON_ERROR));
        $commands = ValidatedData::object($schemas['commands'] ?? null);
        $entry = ValidatedData::object($commands[$name] ?? null);
        $command = $name === 'agent.install'
            ? ['anyOf' => [['type' => 'object', 'additionalProperties' => false], ['type' => 'array', 'maxItems' => 0]]]
            : ValidatedData::object($entry['input'] ?? null);
        $definitions = $command['$defs'] ?? [];
        unset($command['$defs']);

        return [
            'type' => 'object', 'additionalProperties' => false, '$defs' => $definitions,
            'required' => ['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_fingerprint', 'bootstrap_user', 'bootstrap_identity', 'command_input'],
            'properties' => [
                'agent_pin' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                'bootstrap_host' => ['type' => 'string', 'minLength' => 1],
                'bootstrap_port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'bootstrap_fingerprint' => ['type' => 'string', 'pattern' => '^SHA256:[A-Za-z0-9+/]{43}$'],
                'bootstrap_user' => ['type' => 'string', 'minLength' => 1],
                'bootstrap_identity' => ['type' => 'string', 'minLength' => 1],
                'command_input' => $command,
            ],
        ];
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The bootstrap invocation does not match the admitted Node.', 422);
    }
}
