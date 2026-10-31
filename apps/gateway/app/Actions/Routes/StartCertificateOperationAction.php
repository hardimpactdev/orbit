<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Routes\NativeRouteCertificateInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;

/** Records one leaf intent. The signer and Node effects run later through Swamp. */
final readonly class StartCertificateOperationAction
{
    public function __construct(private StartOperationAction $operations, private DevelopmentSiteRepository $sites) {}

    public function converge(Node $node, string $scope, string $domain, ?string $requestId = null, ?string $triggeredBy = 'operator'): Operation
    {
        $this->assertDirectWorker($node);

        return $this->operations->execute('certificate.converge', 'node_certificate', $node->id, $node->id,
            $this->convergenceInput($node, $scope, $domain), $triggeredBy, $requestId);
    }

    public function remove(Node $node, string $scope, ?string $requestId = null, ?string $triggeredBy = 'operator'): Operation
    {
        $this->assertDirectWorker($node);

        return $this->operations->execute('certificate.remove', 'node_certificate', $node->id, $node->id,
            $this->removalInput($node, $scope), $triggeredBy, $requestId);
    }

    /** Admitted public leaf input, also used by Route parent workflows.
     * @return array<string, mixed>
     */
    public function convergenceInput(Node $node, string $scope, string $domain): array
    {
        return app(NativeRouteCertificateInput::class)->convergenceInput($node, $scope, $domain);
    }

    /** Admission runs after the caller withdraws all stored sites for this scope.
     * @return array<string, mixed>
     */
    public function removalInput(Node $node, string $scope): array
    {
        $input = $this->scopeInput($node, $scope);
        if ($this->sites->forNode($node)->contains(
            static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
            throw new ResourceOperationException('app-dev.certificate_in_use', 'A stored Caddy site still uses this certificate.', 409);
        }

        return [...$input, 'in_use' => false];
    }

    /** @return array<string, mixed> */
    private function scopeInput(Node $node, string $scope): array
    {
        return app(NativeRouteCertificateInput::class)->scopeInput($node, $scope);
    }

    private function assertDirectWorker(Node $node): void
    {
        if (in_array($node->id, [1, 18], true)) {
            throw new ResourceOperationException('certificate.proxy_transport_unavailable', 'Native certificate commands need the shared proxy transport for this Node.', 503);
        }
    }
}
