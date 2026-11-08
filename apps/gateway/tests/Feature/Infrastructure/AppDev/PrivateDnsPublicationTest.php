<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRoutePublicationArtifacts;
use App\Infrastructure\AppDev\PrivateDnsPublication;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

function publicationState(string $revision, string $gateway = '10.44.0.2'): array
{
    return ['revision' => $revision, 'listen' => ['10.44.0.1'], 'tlds' => [['name' => 'test', 'address' => '10.44.0.7']],
        'records' => [['name' => 'gateway.orbit', 'address' => $gateway, 'pair' => true]]];
}

describe('PrivateDnsPublication', function (): void {
    it('renders the complete state with a revision, and every render takes a newer one', function (): void {
        $first = app(PrivateDnsPublication::class)->render();
        $second = app(PrivateDnsPublication::class)->render();

        expect($first)->toHaveKeys(['revision', 'listen', 'tlds', 'records'])
            ->and(array_keys($first))->toBe(['revision', 'listen', 'tlds', 'records'])
            ->and($first['revision'])->toBeString()
            ->and((int) $second['revision'])->toBeGreaterThan((int) $first['revision']);
    });

    it('names the vpn Node with its connection and the render', function (): void {
        SwampNodeFixture::ensure();
        $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'public_ssh_host' => '10.44.0.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit', 'wireguard_public_key' => 'vpn-key',
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'vpn-host-key', 'ssh_host_fingerprint' => 'SHA256:vpn']));
        $node->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);

        $entry = app(PrivateDnsPublication::class)->entry($node);
        $preparation = app(PrivateDnsPublication::class)->entry($node, clusterOnly: true);

        expect($entry['node_id'])->toBe($node->id)
            ->and($entry['connection'])->toMatchArray(['nodeHost' => '10.44.0.1', 'nodeUser' => 'orbit', 'become' => true])
            ->and($entry['host_keys'])->toBe(['ssh-ed25519 vpn-host-key'])
            ->and($entry['dns']['listen'])->toBe(['10.44.0.1'])
            ->and($entry['dns'])->not->toHaveKey('cluster_only')
            ->and($preparation['dns']['cluster_only'])->toBeTrue();
    });

    it('restamps a render with a newer revision and leaves its content', function (): void {
        $old = publicationState('100');
        $restamped = app(PrivateDnsPublication::class)->restamp($old);

        expect((int) $restamped['revision'])->toBeGreaterThan(100)
            ->and([...$restamped, 'revision' => '100'])->toBe($old);
    });

    it('compares two renders without their revision', function (): void {
        expect(PrivateDnsPublication::same(publicationState('1'), publicationState('2')))->toBeTrue()
            ->and(PrivateDnsPublication::same(publicationState('1'), publicationState('1', '10.44.0.3')))->toBeFalse()
            ->and(PrivateDnsPublication::same(publicationState('1'), null))->toBeFalse()
            ->and(PrivateDnsPublication::same(null, null))->toBeTrue();
    });

    it('captures member-only policy changes while ignoring revisions in the publication content digest', function (): void {
        $first = [...publicationState('1'), 'members' => [['policy' => ['revision' => '1', 'delegate' => ['server' => '192.168.6.20']]]]];
        $newRevision = app(PrivateDnsPublication::class)->restamp($first);
        $moved = $newRevision;
        $moved['members'][0]['policy']['delegate']['server'] = '192.168.6.21';
        $artifacts = ['dns' => []];
        $indexes = [];
        $capture = app(NativeRoutePublicationArtifacts::class);
        $a = $capture->intern($artifacts, 'dns', $first, $indexes);
        $b = $capture->intern($artifacts, 'dns', $newRevision, $indexes);
        $c = $capture->intern($artifacts, 'dns', $moved, $indexes);

        expect($artifacts['dns'][$a]['content_digest'])->toBe($artifacts['dns'][$b]['content_digest'])
            ->not->toBe($artifacts['dns'][$c]['content_digest']);
    });
});
