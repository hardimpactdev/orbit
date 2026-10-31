<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;

/** Complete public Caddy intent, rendered before native dispatch rather than by a step GET. */
final readonly class NativeCaddyPublicationInput
{
    /** @return array{caddy_publication: array<string,mixed>} */
    public function forOperation(Operation $operation): array
    {
        if ($operation->kind !== 'route.caddy_converge' || $operation->node_id === null) {
            throw new ResourceOperationException('route.publication_invalid', 'Caddy publication requires its admitted Node.', 422);
        }

        return ['caddy_publication' => $this->forNode(Node::query()->findOrFail($operation->node_id))];
    }

    /** @return array<string,mixed> */
    public function forNode(Node $node): array
    {
        $publication = CaddyPublicationPlan::forNode($node);
        if (isset($publication['error_code'])) {
            throw new ResourceOperationException('caddy.render_failed', 'The admitted Node Caddy configuration cannot be rendered.', 422);
        }

        return [...array_intersect_key($publication, array_flip(['node_id', 'user', 'caddy'])), ...$this->connectionForNode($node)];
    }

    /** A null pin list is a required parent binding, never an executable SSH fallback.
     * @return array{control_node_id:209,host_keys:?list<string>,connection:array{nodeHost:string,nodeUser:string,nodePort:int,nodeIdentityFile:string}}
     */
    public function connectionForCandidate(Node $node, NodeEnrollmentCandidate $candidate): array
    {
        if ($node->id !== $candidate->nodeId) {
            throw new ResourceOperationException('node.role_publication_invalid', 'The prospective connection must name the actual admitted Node.', 422);
        }
        $ssh = app(SwampSshExecutionInput::class);
        $pinned = is_string($node->ssh_host_key_type) && $node->ssh_host_key_type !== ''
            && is_string($node->ssh_host_key) && $node->ssh_host_key !== '';
        $identity = $ssh->identity();
        $hostKeys = null;
        if ($pinned) {
            $stored = $ssh->forNode($node);
            $hostKeys = $stored['target']['host_keys'];
        }

        return ['control_node_id' => $identity['control_node_id'],
            'host_keys' => $hostKeys,
            'connection' => ['nodeHost' => $candidate->wireguardIp, 'nodeUser' => $candidate->user,
                'nodePort' => 22, 'nodeIdentityFile' => $identity['identity_file']]];
    }

    /** @return array{control_node_id:209,host_keys:list<string>,connection:array{nodeHost:string,nodeUser:string,nodePort:int,nodeIdentityFile:string,become:true}} */
    public function connectionForNode(Node $node): array
    {
        $ssh = app(SwampSshExecutionInput::class)->forNode($node);
        $target = $ssh['target'];
        $address = app(RouteRenderContext::class)->wireguardIp($node);
        if ($address === null) {
            throw new ResourceOperationException('caddy.connection_unavailable', 'Publication requires its admitted Node SSH address.', 503);
        }

        return ['control_node_id' => $ssh['control_node_id'], 'host_keys' => $target['host_keys'], 'connection' => [
            'nodeHost' => $address, 'nodeUser' => $target['user'], 'nodePort' => $target['port'],
            'nodeIdentityFile' => $ssh['identity_file'], 'become' => true,
        ]];
    }
}
