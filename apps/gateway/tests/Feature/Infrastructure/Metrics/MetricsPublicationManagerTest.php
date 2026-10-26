<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Metrics\MetricsCertificatePublisher;
use App\Infrastructure\Metrics\MetricsPublicationFirewall;
use App\Infrastructure\Metrics\MetricsPublicationManager;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\RecordingNodeCaddyBuilds;

it('publishes the certificate and firewall, requests a Gateway build, and publishes DNS last', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        projection: new MetricsPublicationManagerProjectionOwner($events),
    );

    $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($events)->toBe([
        'projection:enter',
        'certificate:issue',
        'process:certificate',
        'firewall:converge',
        'build:gateway',
        'dns:metrics',
        'projection:leave',
    ])
        ->and(new CaddySiteCertificates()->published(metrics_publication_manager_node('gateway', '10.44.0.1')->id, MetricsCaddySiteSource::CertificateSlug))->toBeTrue();
});

it('writes no Caddy file on the Gateway itself', function (): void {
    $events = [];
    $invocations = [];
    $manager = metrics_publication_manager(
        $events,
        invocations: $invocations,
    );

    $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($invocations)->not->toBeEmpty();

    foreach ($invocations as $invocation) {
        expect(json_encode($invocation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->not->toContain('orbit-versions')
            ->not->toContain('metrics.caddy');
    }
});

it('keeps its error code, names the build failure, and keeps the certificate a failed role still names', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        buildFailure: new NodeCaddyBuildException('gateway', 'reload', 'Error: loading new config'),
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('metrics.caddy_publication_failed')
            ->and($exception->details)->toBe(['node' => 'gateway', 'stage' => 'reload', 'message' => 'Error: loading new config']);
    });

    expect($events)->toBe(['certificate:issue', 'process:certificate', 'firewall:converge', 'build:gateway']);
});

it('removes publication in DNS, build, firewall, certificate order', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        projection: new MetricsPublicationManagerProjectionOwner($events),
    );

    $manager->remove(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($events)->toBe([
        'projection:enter',
        'dns:none',
        'build:gateway',
        'firewall:remove',
        'process:certificate',
        'projection:leave',
    ]);
});

it('keeps the certificate when the Metrics role moved and its site still renders', function (): void {
    new CaddySiteCertificates()->record(metrics_publication_manager_node('gateway', '10.44.0.1')->id, MetricsCaddySiteSource::CertificateSlug);
    $target = metrics_publication_manager_node('metrics-next', '10.44.0.4');
    $target->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $events = [];
    $manager = metrics_publication_manager(
        $events,
    );

    $manager->remove(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($events)->toBe(['dns:none', 'build:gateway', 'firewall:remove'])
        ->and(new CaddySiteCertificates()->published(metrics_publication_manager_node('gateway', '10.44.0.1')->id, MetricsCaddySiteSource::CertificateSlug))->toBeTrue();
});

it('retracts the Gateway side by building the Gateway before it removes the certificate', function (): void {
    $gateway = metrics_publication_manager_node('gateway', '10.44.0.1');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    new CaddySiteCertificates()->record($gateway->id, MetricsCaddySiteSource::CertificateSlug);
    $events = [];

    metrics_publication_manager($events)->retract(metrics_publication_manager_node('metrics', '10.44.0.3'));

    expect($events)->toBe(['dns:none', 'build:gateway', 'process:certificate'])
        ->and(new CaddySiteCertificates()->published($gateway->id, MetricsCaddySiteSource::CertificateSlug))->toBeFalse();
});

it('retains projection ownership when DNS publication fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        failDns: true,
        projection: new MetricsPublicationManagerProjectionOwner($events),
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))->toThrow(RuntimeException::class, 'DNS publication failed.');

    expect($events[0])
        ->toBe('projection:enter')
        ->and($events[array_key_last($events)])
        ->toBe('projection:leave')
        ->and($events)->not->toContain('firewall:remove');
});

/**
 * @param  list<string>  $events
 * @param  list<array<string, mixed>>  $invocations  The input of each Metrics certificate agent call.
 */
function metrics_publication_manager(
    array &$events,
    ?NodeCaddyBuildException $buildFailure = null,
    bool $failDns = false,
    ?DevelopmentProjectionOperationLock $projection = null,
    array &$invocations = [],
): MetricsPublicationManager {
    $agents = new FakeAgentCommandRunner()->answer('metrics.certificate', static function (array $input) use (&$events, &$invocations): array {
        $invocations[] = $input;
        $events[] = 'process:certificate';

        return $input['operation'] === 'publish' ? ['change' => 'created', 'previous' => null] : [];
    });

    $agents->answer('metrics.publication-firewall', static function (array $input) use (&$events): array {
        $events[] = 'firewall:'.$input['action']['operation'];

        return ['changed' => true];
    });

    return new MetricsPublicationManager(
        certificates: new MetricsPublicationManagerCertificateIssuer($events),
        certificatePublisher: new MetricsCertificatePublisher($agents),
        builds: new RecordingNodeCaddyBuilds($events, $buildFailure),
        firewall: new MetricsPublicationFirewall($agents, new CommandDeadline),
        dns: new MetricsPublicationManagerDns($events, $failDns),
        projection: $projection ?? new MetricsPublicationManagerProjectionOwner,
    );
}

function metrics_publication_manager_node(string $name, string $address): Node
{
    return Node::query()->firstOrCreate(['name' => $name], [
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $address,
        'user' => 'orbit',
    ]);
}

final class MetricsPublicationManagerCertificateIssuer implements GatewayCertificateIssuer
{
    /** @param list<string> $events */
    public function __construct(
        private array &$events,
    ) {}

    public function issue(string $domain, string $wireguardIp): GatewayCertificatePaths
    {
        $this->events[] = 'certificate:issue';
        $directory = sys_get_temp_dir().'/orbit-metrics-manager-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents("{$directory}/metrics.key", "KEY\n");
        file_put_contents("{$directory}/metrics.pem", "CERTIFICATE\n");

        return new GatewayCertificatePaths("{$directory}/metrics.key", "{$directory}/metrics.pem");
    }
}

final class MetricsPublicationManagerDns implements PrivateDnsManager
{
    /** @param list<string> $events */
    public function __construct(
        private array &$events,
        private bool $fail,
    ) {}

    public function converge(?Node $pendingNode = null): void
    {
        $this->events[] = 'dns:'.($pendingNode?->name ?? 'none');

        if ($this->fail) {
            throw new RuntimeException('DNS publication failed.');
        }
    }
}

final class MetricsPublicationManagerProjectionOwner implements DevelopmentProjectionOperationLock
{
    /** @var list<string>|null */
    private ?array $events;

    /** @param list<string>|null $events */
    public function __construct(?array &$events = null)
    {
        $this->events = &$events;
    }

    public function run(Closure $operation): mixed
    {
        if (is_array($this->events)) {
            $this->events[] = 'projection:enter';
        }

        try {
            return $operation();
        } finally {
            if (is_array($this->events)) {
                $this->events[] = 'projection:leave';
            }
        }
    }
}
