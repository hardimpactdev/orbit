<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\LinuxUserName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;

/** Public DB-only leaf intent. The placed native worker resolves its own managed account. */
final readonly class NativeRouteCertificateInput
{
    /** @return array<string,mixed> */
    public function convergenceInput(Node $node, string $scope, string $domain): array
    {
        if ($node->platform !== 'linux' || ! LinuxUserName::isValid($node->user)
            || preg_match('/\A(?:app-instance-[1-9][0-9]*(?:-hostname-change)?|route-[1-9][0-9]*(?:-router(?:-hostname-change)?|-ingress)?)\z/D', $scope) !== 1) {
            throw new ResourceOperationException('certificate.scope_invalid', 'The leaf must name an admitted Linux Node account and Route scope.', 422);
        }

        return ['node_id' => $node->id, 'user' => $node->user, 'scope' => $scope, 'domain' => RouteDomain::validate($domain),
            'version' => bin2hex(random_bytes(8)), 'root_hash' => hash('sha256', app(LeafCertificateSigner::class)->rootCertificate()),
            'agent_sha256' => app(AgentDelivery::class)->pin($node)];
    }
}
