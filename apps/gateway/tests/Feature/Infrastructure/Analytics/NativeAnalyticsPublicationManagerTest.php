<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Infrastructure\Analytics\AnalyticsCaddySiteRenderer;
use App\Infrastructure\Analytics\AnalyticsCertificatePublisher;
use App\Infrastructure\Analytics\NativeAnalyticsPublicationManager;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Caddy\RoleCaddyCertificate;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Models\Node;
use Tests\Support\RecordingNodeCaddyBuilds;
use Tests\Support\RoleAgents;

it('waits for Plausible, issues the certificate, publishes the Caddy site, then converges DNS last', function (): void {
    $events = [];
    $manager = analytics_publication_manager($events);

    $manager->converge(analytics_publication_node());

    expect($events)->toBe(['ssh:health', 'ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build:services', 'dns:converge'])
        ->and(new CaddySiteCertificates()->published(analytics_publication_node()->id, AnalyticsCaddySiteSource::CertificateSlug))->toBeTrue();
});

it('publishes nothing when Plausible does not become healthy', function (): void {
    $events = [];
    $manager = analytics_publication_manager($events, failHealth: true);

    expect(fn () => $manager->converge(analytics_publication_node()))
        ->toThrow(fn (NodeRoleOperationException $exception) => expect($exception->underlyingErrorCode)->toBe('analytics.plausible_unhealthy'));

    expect($events)->toBe(['ssh:health']);
});

it('throws when the certificate SSH push fails', function (): void {
    $events = [];
    $manager = analytics_publication_manager($events, failCertificate: true);

    expect(fn () => $manager->converge(analytics_publication_node()))
        ->toThrow(NodeRoleOperationException::class);

    expect($events)->toBe(['ssh:health', 'ssh:caddy-source', 'certificate:issue', 'ssh:certificate']);
});

it('keeps its error code and names the Node, stage, and Caddy message when the build fails', function (): void {
    $events = [];
    $manager = analytics_publication_manager($events, buildFailure: new NodeCaddyBuildException('services', 'addresses', 'The build binds 192.168.6.30, which is not an address on this Node.'));

    expect(fn () => $manager->converge(analytics_publication_node()))
        ->toThrow(function (NodeRoleOperationException $exception): void {
            expect($exception->underlyingErrorCode)->toBe('analytics.caddy_publication_failed')
                ->and($exception->step)->toBe('analytics-caddy')
                ->and($exception->getMessage())->toContain('Node [services] failed at stage [addresses]: The build binds 192.168.6.30');
        });

    expect($events)->toBe(['ssh:health', 'ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build:services']);
});

it('builds the Node before it removes the certificate, then converges DNS without the node', function (): void {
    $events = [];
    $manager = analytics_publication_manager($events);

    new CaddySiteCertificates()->record(analytics_publication_node()->id, AnalyticsCaddySiteSource::CertificateSlug);

    $manager->remove(analytics_publication_node());

    expect($events)->toBe(['build:services', 'ssh:certificate-remove', 'dns:converge-empty'])
        ->and(new CaddySiteCertificates()->published(analytics_publication_node()->id, AnalyticsCaddySiteSource::CertificateSlug))->toBeFalse();
});

it('touches only the Gateway-side DNS record when the node is unreachable', function (): void {
    $events = [];
    $manager = analytics_publication_manager($events);

    $manager->removeUnreachable(analytics_publication_node());

    expect($events)->toBe(['dns:converge-empty']);
});

function analytics_publication_node(): Node
{
    return Node::query()->firstOrCreate(['name' => 'services'], [
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.9',
    ]);
}

function analytics_publication_manager(
    array &$events,
    bool $failCertificate = false,
    ?NodeCaddyBuildException $buildFailure = null,
    bool $failHealth = false,
): NativeAnalyticsPublicationManager {
    $certificateDirectory = sys_get_temp_dir().'/orbit-analytics-test-'.bin2hex(random_bytes(4));
    mkdir($certificateDirectory);
    file_put_contents($certificateDirectory.'/analytics.pem', "CERT\n");
    file_put_contents($certificateDirectory.'/analytics.key', "KEY\n");

    return new NativeAnalyticsPublicationManager(
        certificates: new class($events, $certificateDirectory) implements GatewayCertificateIssuer
        {
            public function __construct(private array &$events, private string $directory) {}

            public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
            {
                $this->events[] = 'certificate:issue';

                return new GatewayCertificatePaths(
                    privateKeyPath: $this->directory.'/analytics.key',
                    certificatePath: $this->directory.'/analytics.pem',
                );
            }
        },
        certificatePublisher: new AnalyticsCertificatePublisher(new RoleCaddyCertificate($agents = RoleAgents::recording($events, $failCertificate, $failHealth))),
        builds: new RecordingNodeCaddyBuilds($events, $buildFailure),
        dns: new class($events) implements PrivateDnsManager
        {
            public function __construct(private array &$events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events[] = $pendingNode instanceof Node ? 'dns:converge' : 'dns:converge-empty';
            }
        },
        agents: $agents,
        caddyPackage: new AgentCaddyPackage($agents),
    );
}

it('renders a site that proxies analytics.orbit to Plausible on the WireGuard address', function (): void {
    $site = new AnalyticsCaddySiteRenderer()->render('10.44.0.3');

    expect($site)->toStartWith('# Managed by Orbit: analytics')
        ->toContain('analytics.orbit {')
        ->toContain('bind __ORBIT_ANALYTICS_BIND__')
        ->toContain('tls /etc/caddy/orbit-analytics-cert-current/analytics.pem /etc/caddy/orbit-analytics-cert-current/analytics.key')
        ->toContain('reverse_proxy 10.44.0.3:8000');
});
