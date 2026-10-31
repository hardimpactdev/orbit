<?php

declare(strict_types=1);

namespace App\Domain\Routes;

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
