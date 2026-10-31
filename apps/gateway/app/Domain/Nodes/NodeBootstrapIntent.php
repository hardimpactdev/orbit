<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use App\Models\Operation;

/** Public first-contact input. Candidate identity is never committed here. */
final readonly class NodeBootstrapIntent
{
    public const array Kinds = ['node.bootstrap_probe', 'node.bootstrap_install', 'node.bootstrap_host', 'node.bootstrap_verify', 'node.macos_inspect'];

    public function __construct(private NodeBootstrapCommandFactory $commands,
        private NodeRoleFirewallInput $firewall, private AgentDelivery $delivery, private NodeEnrollmentPolicy $policy) {}

    /** @return array<string,mixed> */
    public function input(Node $node, string $kind, ?ProvisionNodeData $request = null): array
    {
        if (! in_array($kind, self::Kinds, true) || ! $node->exists || ! in_array($node->status->value, ['active', 'failed', 'provisioning'], true)) {
            throw $this->invalid();
        }
        $candidate = clone $node;
        $bootstrap = $node->user;
        $fingerprint = $node->ssh_host_fingerprint;
        $expected = MachineArchitecture::normalize($node->architecture);
        $roleless = ! $node->roles()->exists();
        if ($request !== null) {
            if ($request->name !== $node->name) {
                throw $this->invalid();
            }
            $spec = $this->policy->prepare($node, $request);
            if ($spec['mode'] === 'tld_only') {
                throw $this->invalid();
            }
            $candidate->fill($spec['candidate']);
            $bootstrap = $spec['bootstrap_user'];
            $fingerprint = $spec['fingerprint'];
            $expected = $spec['requested_architecture'];
            $roleless = $spec['roleless_operator'];
        }
        if (! in_array($candidate->platform, ['linux', 'macos'], true)
            || ($kind === 'node.macos_inspect' && $candidate->platform !== 'macos')
            || (in_array($kind, ['node.bootstrap_host', 'node.bootstrap_verify'], true) && $candidate->platform !== 'linux')
            || ! LinuxUserName::isValid($bootstrap) || ! LinuxUserName::isValid($candidate->user)
            || $candidate->public_ssh_host === ''
            || ! is_string($candidate->wireguard_ip) || filter_var($candidate->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! is_string($fingerprint) || preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $fingerprint) !== 1) {
            throw $this->invalid();
        }
        $artifacts = [];
        if ($kind !== 'node.bootstrap_probe') {
            foreach ($this->delivery->artifacts() as $key => $artifact) {
                $url = $artifact['url'] ?? null;
                if (in_array($key, ['linux-x86_64', 'macos-x86_64', 'macos-aarch64'], true)
                    && is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https'
                    && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null) {
                    $artifacts[$key] = ['sha256' => $artifact['sha256'], 'url' => $url];
                }
            }
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'platform' => $candidate->platform,
            'target' => ['node_id' => $node->id, 'host' => $candidate->public_ssh_host, 'port' => $candidate->public_ssh_port,
                'user' => in_array($kind, ['node.bootstrap_verify', 'node.macos_inspect'], true) ? $candidate->user : $bootstrap,
                'fingerprint' => $fingerprint,
                ...($kind === 'node.bootstrap_probe' ? ['host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key)
                    ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []] : [])],
            'managed_user' => $candidate->user, 'expected_architecture' => $expected, 'address' => $candidate->wireguard_ip,
            'artifacts' => $artifacts,
            'command' => $candidate->platform === 'linux' ? $this->commands->input($candidate, $candidate->user, $roleless) : null,
            'firewall' => $candidate->platform === 'linux' && $roleless ? $this->firewall->publicSsh($candidate, true) : null];
    }

    /** Custody is the admitted target; execution remains on the Gateway holding its SSH key. */
    public function owns(Operation $operation): bool
    {
        return in_array($operation->kind, self::Kinds, true) && $operation->target_type === 'node'
            && $operation->node_id > 0 && $operation->target_id > 0
            && is_array($operation->input['bootstrap'] ?? null)
            && ($operation->input['bootstrap']['node_id'] ?? null) === $operation->target_id
            && is_array($operation->input['bootstrap']['target'] ?? null)
            && ($operation->input['bootstrap']['target']['node_id'] ?? null) === $operation->target_id;
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('node.bootstrap_intent_invalid', 'Bootstrap needs a pinned Node endpoint and a valid candidate account.', 422);
    }
}
