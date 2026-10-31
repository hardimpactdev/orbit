<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteNodeRender;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
});

/** @param array<string,mixed> $changes */
function footprintCandidate(Node $node, array $changes = []): NodeEnrollmentCandidate
{
    return new NodeEnrollmentCandidate(...array_replace([
        'nodeId' => $node->id, 'name' => $node->name, 'user' => 'next-orbit', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguardIp' => '10.44.0.201', 'publicSshHost' => '192.0.2.201',
        'publicSshPort' => 22, 'clusterId' => null, 'tld' => 'next.internal', 'lanIp' => null,
        'wireguardEndpointOverride' => null, 'dnsServerOverride' => '10.44.0.200',
    ], $changes));
}

it('captures roleless admitted observer settings and user in both payload and digest without writes', function (): void {
    FleetFixtures::node('footprint-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('footprint-roleless');
    $node->update(['settings' => ['apps' => ['path' => '/srv/old']], 'tld' => 'old.internal']);
    $before = $node->fresh()->getAttributes();
    $settingsBefore = Setting::query()->get()->toArray();
    $input = app(NodeConvergeInput::class);
    $stored = $input->captureFootprint($node);
    $capture = $input->captureFootprint($node, footprintCandidate($node), ['apps' => ['path' => '/home/next-orbit/projects']]);
    $otherSettings = $input->captureFootprint($node, footprintCandidate($node), ['apps' => ['path' => '/srv/next']]);
    $cleared = $input->captureFootprint($node, footprintCandidate($node), []);

    expect($capture['payloads']['agent']['user'])->toBe('next-orbit')
        ->and($capture['payloads']['agent']['apps_root'])->toBe('/home/next-orbit/projects')
        ->and($capture['payloads']['agent']['unit'])->toContain('BindReadOnlyPaths=-/home/next-orbit/projects')
        ->and($capture['payloads']['agent']['configuration'])->not->toContain('[swamp_worker]')
        ->and($cleared['payloads']['agent']['apps_root'])->toBeNull()
        ->and($capture)->not->toHaveKey('secret_hash')
        ->and(collect($capture['artifacts'])->firstWhere('name', 'agent')['digest'])
        ->not->toBe(collect($stored['artifacts'])->firstWhere('name', 'agent')['digest'])
        ->not->toBe(collect($otherSettings['artifacts'])->firstWhere('name', 'agent')['digest'])
        ->and($input->captureFootprint($node))->toBe($stored)
        ->and($node->fresh()->getAttributes())->toBe($before)
        ->and(Setting::query()->get()->toArray())->toBe($settingsBefore)
        ->and(Operation::query()->count())->toBe(0);
});

it('uses admitted DNS override and replaces the old TLD in network payload and footprint digest', function (): void {
    FleetFixtures::node('dns-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('dns-member', [RoleName::Database]);
    $node->update(['tld' => 'old.internal', 'dns_server_override' => '10.44.0.199', 'wireguard_public_key' => 'public-peer']);
    $input = app(NodeConvergeInput::class);
    $before = $input->captureFootprint($node);
    $capture = $input->captureFootprint($node, footprintCandidate($node));
    expect($capture['network']['peer_dns']['dns_server'])->toBe('10.44.0.200')
        ->and($capture['network']['peer_dns']['domains'])->toContain('next.internal')->not->toContain('old.internal')
        ->and(collect($capture['artifacts'])->firstWhere('name', 'peer-dns')['digest'])
        ->not->toBe(collect($before['artifacts'])->firstWhere('name', 'peer-dns')['digest'])
        ->and($input->captureFootprint($node))->toBe($before);

    $removed = $input->captureFootprint($node, footprintCandidate($node, ['tld' => null]));
    expect($removed['network']['peer_dns']['domains'])->not->toContain('old.internal', 'next.internal');
    $withdrawn = app(RouteRenderContext::class)->renderEnrollment(footprintCandidate($node),
        fn () => app(PeerDnsPolicy::class)->domains($node, true));
    expect($withdrawn)->not->toContain('old.internal', 'next.internal');
});

it('captures the desired supported Linux observer for a real provisional reservation without granting management', function (): void {
    FleetFixtures::node('new-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('new-reservation', managed: false, status: LifecycleStatus::Provisioning);
    $node->update(['architecture' => null, 'wireguard_ip' => null]);
    $before = $node->fresh()->getAttributes();
    $capture = app(NodeConvergeInput::class)->captureFootprint($node,
        footprintCandidate($node, ['architecture' => null]), ['apps' => ['path' => '/srv/new']]);
    expect($capture['payloads']['agent']['apps_root'])->toBe('/srv/new')
        ->and($capture['agent_sha256'])->toBe(app(AgentDelivery::class)->artifacts()['linux-x86_64']['sha256'])
        ->and($capture['refusal'])->toBeNull()
        ->and(app(ManagedNodeEligibility::class)->allows($node))->toBeFalse()
        ->and(app(NodeFootprint::class)->expected($node)->artifacts)->not->toHaveKey('agent')
        ->and($node->fresh()->getAttributes())->toBe($before);
});

it('restores settings identity and DNS scopes across nested captures actual renders and failures', function (): void {
    $node = FleetFixtures::node('nested-footprint');
    $node->update(['settings' => ['apps' => ['path' => '/srv/stored']], 'dns_server_override' => '10.44.0.199']);
    $context = app(RouteRenderContext::class);
    $policy = app(PeerDnsPolicy::class);
    $context->renderEnrollment(footprintCandidate($node), function () use ($node, $context, $policy): void {
        $context->renderSettings($node, ['apps' => ['path' => '/srv/outer']], function () use ($node, $context, $policy): void {
            expect(fn () => $context->renderSettings($node, [], function () use ($node, $context): never {
                expect($context->settings($node))->toBe([]);
                throw new RuntimeException('settings failure');
            }))->toThrow(RuntimeException::class, 'settings failure');
            expect($context->settings($node))->toBe(['apps' => ['path' => '/srv/outer']]);
            $context->renderActual(function () use ($node, $context, $policy): void {
                expect($context->settings($node))->toBe(['apps' => ['path' => '/srv/stored']])
                    ->and($context->user($node))->toBe('orbit')
                    ->and($policy->server($node))->toBe('10.44.0.199');
            });
            expect($context->user($node))->toBe('next-orbit')->and($policy->server($node))->toBe('10.44.0.200');
        });
    });
    $context->render(new NativeRouteNodeRender($node->id, '10.44.0.202', 'render.internal'), function () use ($node, $context, $policy): void {
        expect($context->user($node))->toBe('orbit')->and($context->settings($node))->toBe(['apps' => ['path' => '/srv/stored']])
            ->and($policy->server($node))->toBe('10.44.0.199');
    });
    expect($context->nodeId())->toBeNull();
});

it('rejects a candidate for another actual Node before rendering any footprint', function (): void {
    $node = FleetFixtures::node('candidate-owner');
    expect(fn () => app(NodeConvergeInput::class)->captureFootprint($node, footprintCandidate($node, ['nodeId' => $node->id + 1])))
        ->toThrow(ResourceOperationException::class, 'The footprint candidate must name the actual reserved Node.');
    expect(app(RouteRenderContext::class)->nodeId())->toBeNull();
});
