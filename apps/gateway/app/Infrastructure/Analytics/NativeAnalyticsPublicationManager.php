<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Analytics\AnalyticsPublicationManager;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

final readonly class NativeAnalyticsPublicationManager implements AnalyticsPublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private AnalyticsCertificatePublisher $certificatePublisher,
        private NodeCaddyBuilds $builds,
        private PrivateDnsManager $dns,
        private AgentCommandRunner $agents,
        private AgentCaddyPackage $caddyPackage,
        private ?CaddySiteCertificates $siteCertificates = null,
    ) {}

    public function converge(Node $node): void
    {
        $address = $this->address($node);
        $this->awaitPlausible($node, $address);
        $this->ensureCaddy($node);
        $certificate = $this->certificates->issue(AnalyticsHostname::Value, $address);
        $certificatePem = $this->read($certificate->certificatePath);
        $privateKeyPem = $this->read($certificate->privateKeyPath);

        $certificateResult = $this->certificatePublisher->publish($node, $certificatePem, $privateKeyPem);

        if (! $certificateResult->succeeded()) {
            throw new NodeRoleOperationException(
                'analytics-certificate',
                'node_role.convergence_failed',
                'analytics.certificate_publication_failed',
                "Analytics certificate publication failed on node [{$node->name}].",
                $certificateResult,
            );
        }

        $this->certificateRecords()->record($node->id, AnalyticsCaddySiteSource::CertificateSlug);

        $this->build($node);
        $this->dns->converge($node);
    }

    /**
     * The analytics role has no package step of its own, so the dashboard host is the point where
     * this Node needs a Caddy Orbit can render against. `AgentCaddyPackage` owns the pin and floor.
     */
    private function ensureCaddy(Node $node): void
    {
        $result = $this->caddyPackage->install($node);

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'caddy-package-source',
                'node_role.convergence_failed',
                'analytics.caddy_publication_failed',
                "The Caddy package step failed on node [{$node->name}].",
                $result,
            );
        }
    }

    /**
     * A started container is not a working Plausible: it first creates and migrates its database,
     * and it restarts forever when it cannot reach its storage. The dashboard is published only
     * once Plausible answers its own health check.
     */
    private function awaitPlausible(Node $node, string $address): void
    {
        try {
            $this->agents->run($node, 'http.await-healthy', [
                'url' => "http://{$address}:".PlausibleProcess::PORT.'/api/health',
                'attempts' => 90,
                'interval_seconds' => 2,
            ]);
            $result = null;
        } catch (AgentCommandRefused|AgentRunnerFailure $failure) {
            $result = new CommandResult(1, '', $failure->getMessage(), 0, false);
        }

        if ($result !== null) {
            throw new NodeRoleOperationException(
                'analytics-health',
                'node_role.convergence_failed',
                'analytics.plausible_unhealthy',
                "Plausible did not become healthy on node [{$node->name}]. Read the logs of its plausible Process.",
                $result,
            );
        }
    }

    /** The role is already `removing`, so the build withdraws its site before the certificate goes. */
    public function remove(Node $node): void
    {
        $this->address($node);
        $this->build($node);
        $this->certificatePublisher->remove($node);
        $this->certificateRecords()->forget($node->id, AnalyticsCaddySiteSource::CertificateSlug);
        $this->dns->converge();
    }

    /** The certificate may stay on the Node, so a later convergence publishes it again before its site renders. */
    public function removeUnreachable(Node $node): void
    {
        $this->certificateRecords()->forget($node->id, AnalyticsCaddySiteSource::CertificateSlug);
        $this->dns->converge();
    }

    private function certificateRecords(): CaddySiteCertificates
    {
        return $this->siteCertificates ?? new CaddySiteCertificates;
    }

    private function build(Node $node): void
    {
        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            throw new NodeRoleOperationException(
                'analytics-caddy',
                'node_role.convergence_failed',
                'analytics.caddy_publication_failed',
                $exception->getMessage(),
                $exception->result(),
                $exception,
            );
        }
    }

    private function address(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new NodeRoleOperationException(
                'analytics-publication',
                'node_role.convergence_failed',
                'analytics.publication_address_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
            );
        }

        return $address;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new NodeRoleOperationException(
                'analytics-certificate',
                'node_role.convergence_failed',
                'analytics.certificate_read_failed',
                "Could not read the issued analytics certificate at [{$path}].",
            );
        }

        return $contents;
    }
}
