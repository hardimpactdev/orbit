<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

/** Public first-contact input. Candidate identity is never committed here. */
final readonly class NodeBootstrapIntent
{
    public const array Kinds = ['node.macos_inspect'];

    public function __construct(private NodeEnrollmentPolicy $policy) {}

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
        }
        if (! in_array($candidate->platform, ['linux', 'macos'], true)
            || $candidate->platform !== 'macos'
            || ! LinuxUserName::isValid($bootstrap) || ! LinuxUserName::isValid($candidate->user)
            || $candidate->public_ssh_host === ''
            || ! is_string($candidate->wireguard_ip) || filter_var($candidate->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! is_string($fingerprint) || preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $fingerprint) !== 1) {
            throw $this->invalid();
        }
        if (! is_string($node->ssh_host_key_type) || ! is_string($node->ssh_host_key)) {
            throw $this->invalid();
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'platform' => $candidate->platform,
            'target' => ['node_id' => $node->id, 'host' => $candidate->public_ssh_host, 'port' => $candidate->public_ssh_port,
                'user' => $candidate->user,
                'fingerprint' => $fingerprint,
                'host_keys' => [$node->ssh_host_key_type.' '.$node->ssh_host_key]],
            'expected_architecture' => $expected, 'address' => $candidate->wireguard_ip];
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('node.bootstrap_intent_invalid', 'Bootstrap needs a pinned Node endpoint and a valid candidate account.', 422);
    }
}
