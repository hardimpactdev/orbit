<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Nodes\CaddyPackageProgram;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Route;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;

final readonly class NativeAiRouterPublicationManager implements AiRouterPublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private AiRouterCertificatePublisher $certificatePublisher,
        private NodeCaddyBuilds $builds,
        private PrivateDnsManager $dns,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ?AiRouterRouteTakeover $takeover = null,
        private ?CaddySiteCertificates $siteCertificates = null,
    ) {}

    public function converge(Node $node, ?Route $takeover = null): void
    {
        $address = $this->address($node);
        $this->ensureCaddy($node, $address);
        $certificate = $this->certificates->issue(AiRouterFootprint::Hostname, $address);
        $certificatePem = $this->read($certificate->certificatePath);
        $privateKeyPem = $this->read($certificate->privateKeyPath);
        $this->run(
            $node,
            $address,
            $this->certificatePublisher->command($certificatePem, $privateKeyPem),
            'ai_router.certificate_publication_failed',
            "ai-router certificate publication failed on node [{$node->name}].",
        );
        $this->certificateRecords()->record($node->id, AiRouterCaddySiteSource::CertificateSlug);

        if (! $takeover instanceof Route) {
            $this->build($node);
            $this->dns->converge($node);

            return;
        }

        $routes = $this->takeover ?? app(AiRouterRouteTakeover::class);
        $routes->publish($takeover, $node, fn () => $this->build($node));
        $this->dns->converge($node);
        $routes->remove($takeover);
    }

    public function remove(Node $node): void
    {
        $address = $this->address($node);
        // The extension is already disabled, so the build withdraws the collector site before its certificate.
        $this->build($node);
        $this->ssh->execute($this->connection($node, $address), $this->certificatePublisher->removeCommand());
        $this->certificateRecords()->forget($node->id, AiRouterCaddySiteSource::CertificateSlug);
        $this->dns->converge();
    }

    private function certificateRecords(): CaddySiteCertificates
    {
        return $this->siteCertificates ?? new CaddySiteCertificates;
    }

    /** The build renders the collector site from the stored extension state. */
    private function build(Node $node): void
    {
        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            throw new ResourceOperationException(
                'ai_router.caddy_publication_failed',
                $exception->getMessage(),
                422,
                $exception,
                $exception->details(),
            );
        }
    }

    private function ensureCaddy(Node $node, string $address): void
    {
        $this->run(
            $node,
            $address,
            CaddyPackageProgram::command(),
            'ai_router.caddy_publication_failed',
            "The Caddy package step failed on node [{$node->name}].",
        );
    }

    private function run(
        Node $node,
        string $address,
        RemoteCommand $command,
        string $errorCode,
        string $message,
    ): void {
        $result = $this->ssh->execute($this->connection($node, $address), $command);

        if (! $result->succeeded()) {
            throw new ResourceOperationException($errorCode, $message, 422);
        }
    }

    private function connection(Node $node, string $address): SshConnection
    {
        return new SshConnection(
            $address,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
        );
    }

    private function address(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'ai_router.node_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
                422,
            );
        }

        return $address;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ResourceOperationException(
                'ai_router.certificate_read_failed',
                "Could not read the issued ai-router certificate at [{$path}].",
                422,
            );
        }

        return $contents;
    }
}
