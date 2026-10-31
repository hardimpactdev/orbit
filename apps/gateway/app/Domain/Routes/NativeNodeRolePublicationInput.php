<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\NodeRolePublicationInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Models\Node;
use Illuminate\Support\Collection;

/** Immutable publication for an existing admitted role; no state or effect is projected here.
 * @phpstan-import-type CertificatePlan from NodeRoleCertificateInput
 */
final readonly class NativeNodeRolePublicationInput
{
    public function __construct(
        private NodeRoleCertificateInput $certificates,
        private NodeRolePublicationInput $roles,
        private NativeRoutePublicationInput $publications,
        private NativeCaddyPublicationInput $connections,
        private DevelopmentSiteRepository $sites,
        private RouteRenderContext $render,
    ) {}

    /** Certificates precede after-Caddy/PHP; after-DNS follows Caddy. Restore selects before.
     * @return array{certificates:CertificatePlan,publications:list<array<string,mixed>>,participant_node_ids:list<int>}
     */
    public function capture(Node $node, RoleName $role): array
    {
        if (CaddySiteRoles::serving($role, $node->id) === []) {
            throw new ResourceOperationException('node.role_publication_invalid', 'Role capture requires its existing admitted serving assignment.', 409);
        }
        $certificates = $this->certificates->capture($node, $role);
        $duties = $this->roles->capture($node, $role);
        $participants = NodeRolePublicationInput::participants($duties);
        $sites = $this->sites->all();
        $rows = [];
        foreach ($participants as $nodeId) {
            $participant = Node::query()->findOrFail($nodeId);
            $before = $this->snapshot($participant, $sites, $duties);
            $after = $this->render->renderRoleCertificates($certificates,
                fn (): array => $this->snapshot($participant, $sites, $duties));
            $rows[] = ['node_id' => $nodeId, ...$this->connections->connectionForNode($participant), 'before' => $before, 'after' => $after];
        }
        $participants = array_values(array_unique([$node->id, 209, ...$participants, ...NodeRoleCertificateInput::participants($certificates)]));
        sort($participants);

        return ['certificates' => $certificates, 'publications' => $rows, 'participant_node_ids' => $participants];
    }

    /** @param Collection<int,DevelopmentSite> $sites
     * @param  array{caddy_node_ids:list<int>,php_node_ids:list<int>,dns_node_ids:list<int>}  $duties
     * @return array<string,mixed>
     */
    private function snapshot(Node $node, Collection $sites, array $duties): array
    {
        $caddy = in_array($node->id, $duties['caddy_node_ids'], true);
        $php = in_array($node->id, $duties['php_node_ids'], true);
        $snapshot = $caddy || $php ? $this->publications->snapshot($node, $sites)
            : ['caddy' => null, 'php' => null, 'certificate_scopes' => []];

        return [...$snapshot, 'caddy' => $caddy ? $snapshot['caddy'] : null,
            'php' => $php ? $snapshot['php'] : null,
            'dns' => in_array($node->id, $duties['dns_node_ids'], true) ? $this->publications->dns($node) : null];
    }
}
