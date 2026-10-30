<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Routes\RouteDomain;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;

/** Records one leaf intent. The signer and Node effects run later through Swamp. */
final readonly class StartCertificateOperationAction
{
    public function __construct(private StartOperationAction $operations, private ManagedUserAccountResolver $accounts, private LeafCertificateSigner $signer, private AgentDelivery $agents, private DevelopmentSiteRepository $sites) {}

    public function converge(Node $node, string $scope, string $domain, ?string $requestId = null, ?string $triggeredBy = 'operator'): Operation
    {
        $this->assertScope($scope);
        $account = $this->accounts->resolve($node);

        return $this->operations->execute('certificate.converge', 'node_certificate', $node->id, $node->id,
            ['scope' => $scope, 'domain' => RouteDomain::validate($domain), 'version' => bin2hex(random_bytes(8)),
                'root_hash' => hash('sha256', $this->signer->rootCertificate()), 'user' => $account->user, 'home' => $account->home, 'agent_sha256' => $this->agents->pin($node)],
            $triggeredBy, $requestId);
    }

    public function remove(Node $node, string $scope, ?string $requestId = null, ?string $triggeredBy = 'operator'): Operation
    {
        $this->assertScope($scope);

        if ($node->platform !== 'linux') {
            throw new ResourceOperationException('certificate.node_unsupported', 'Certificate removal requires a Linux Node.', 422);
        }
        if ($this->sites->forNode($node)->contains(
            static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
            throw new ResourceOperationException('app-dev.certificate_in_use', 'A stored Caddy site still uses this certificate.', 409);
        }

        return $this->operations->execute('certificate.remove', 'node_certificate', $node->id, $node->id,
            ['scope' => $scope, 'home' => $this->accounts->resolve($node)->home, 'agent_sha256' => $this->agents->pin($node)], $triggeredBy, $requestId);
    }

    private function assertScope(string $scope): void
    {
        if (preg_match('/\A(?:app-instance-[1-9][0-9]*(?:-hostname-change)?|route-[1-9][0-9]*(?:-router(?:-hostname-change)?|-ingress)?)\z/D', $scope) !== 1) {
            throw new ResourceOperationException('certificate.scope_invalid', 'A Route or Instance certificate scope is required.', 422);
        }
    }
}
