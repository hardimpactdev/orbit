<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\RoleCaddyCertificate;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteRenderer;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCertificatePublisher;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterPublicationManager;
use Tests\Support\RecordingNodeCaddyBuilds;
use Tests\Support\RoleAgents;

it('installs Caddy, publishes the certificate, requests a Node Caddy build, then converges DNS last', function (): void {
    $events = [];
    $manager = ai_router_publication_manager($events);

    $manager->converge(ai_router_publication_node());

    expect($events)->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build:beast', 'dns:converge'])
        ->and(new CaddySiteCertificates()->published(ai_router_publication_node()->id, AiRouterCaddySiteSource::CertificateSlug))->toBeTrue();
});

it('throws when the certificate SSH push fails', function (): void {
    $events = [];
    $manager = ai_router_publication_manager($events, failCertificate: true);

    expect(fn () => $manager->converge(ai_router_publication_node()))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('ai_router.certificate_publication_failed'));

    expect($events)->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate']);
});

it('keeps its error code and names the Node, stage, and Caddy message when the build fails', function (): void {
    $events = [];
    $manager = ai_router_publication_manager($events, buildFailure: new NodeCaddyBuildException('beast', 'addresses', 'The build binds 192.168.6.30, which is not an address on this Node.'));

    expect(fn () => $manager->converge(ai_router_publication_node()))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('ai_router.caddy_publication_failed')
                ->and($exception->getMessage())->toContain('The build binds 192.168.6.30')
                ->and($exception->details)->toBe([
                    'node' => 'beast',
                    'stage' => 'addresses',
                    'message' => 'The build binds 192.168.6.30, which is not an address on this Node.',
                ]);
        });

    expect($events)->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build:beast']);
});

it('builds the Node before it removes the certificate, then converges DNS without the node', function (): void {
    $events = [];
    $manager = ai_router_publication_manager($events);

    new CaddySiteCertificates()->record(ai_router_publication_node()->id, AiRouterCaddySiteSource::CertificateSlug);

    $manager->remove(ai_router_publication_node());

    expect($events)->toBe(['build:beast', 'ssh:certificate-remove', 'dns:converge-empty'])
        ->and(new CaddySiteCertificates()->published(ai_router_publication_node()->id, AiRouterCaddySiteSource::CertificateSlug))->toBeFalse();
});

it('renders a site that proxies collector.ai-router.orbit to the loopback collector and waits for its restart', function (): void {
    $site = new AiRouterCaddySiteRenderer()->render();

    expect($site)->toStartWith('# Managed by Orbit: ai-router')
        ->toContain("collector.ai-router.orbit {\n")
        ->not
        ->toMatch('/(?<!collector\.)cli-proxy-api\.orbit \{/')
        ->toContain('bind __ORBIT_AI_ROUTER_BIND__')
        ->toContain('tls /etc/caddy/orbit-ai-router-cert-current/ai_router.pem /etc/caddy/orbit-ai-router-cert-current/ai_router.key')
        ->toContain("reverse_proxy 127.0.0.1:8787 {\n        lb_try_duration 5s\n    }");
});

function ai_router_publication_node(): Node
{
    return Node::query()->firstOrCreate(['name' => 'beast'], [
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
}

function ai_router_publication_manager(
    array &$events,
    bool $failCertificate = false,
    ?NodeCaddyBuildException $buildFailure = null,
): NativeAiRouterPublicationManager {
    $certificateDirectory = sys_get_temp_dir().'/orbit-ai-router-test-'.bin2hex(random_bytes(4));
    mkdir($certificateDirectory);
    file_put_contents($certificateDirectory.'/ai_router.pem', "CERT\n");
    file_put_contents($certificateDirectory.'/ai_router.key', "KEY\n");

    return new NativeAiRouterPublicationManager(
        certificates: new class($events, $certificateDirectory) implements GatewayCertificateIssuer
        {
            public function __construct(private array &$events, private string $directory) {}

            public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
            {
                $this->events[] = 'certificate:issue';

                return new GatewayCertificatePaths(
                    privateKeyPath: $this->directory.'/ai_router.key',
                    certificatePath: $this->directory.'/ai_router.pem',
                );
            }
        },
        certificatePublisher: new AiRouterCertificatePublisher(new RoleCaddyCertificate($agents = RoleAgents::recording($events, $failCertificate))),
        builds: new RecordingNodeCaddyBuilds($events, $buildFailure),
        dns: new class($events) implements PrivateDnsManager
        {
            public function __construct(private array &$events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events[] = $pendingNode instanceof Node ? 'dns:converge' : 'dns:converge-empty';
            }
        },
        caddyPackage: new AgentCaddyPackage($agents),
    );
}
