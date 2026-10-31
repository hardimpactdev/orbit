<?php

declare(strict_types=1);

use App\Domain\Routes\NativeRouteLanInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Models\Node;

beforeEach(function (): void {
    $this->policy = app(NativeRouteLanInput::class);
    $this->workload = Node::query()->create(['name' => 'tuple-workload', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'lan_ip' => '192.168.6.9', 'user' => 'orbit']);
    $this->router = Node::query()->create(['name' => 'tuple-router', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2', 'lan_ip' => '192.168.6.2', 'user' => 'orbit']);
    $this->sites = collect();
    foreach (['first.test', 'second.test'] as $index => $domain) {
        $this->sites->push(new DevelopmentSite($this->workload->id, '10.44.0.9', 'app-instance-'.$index, '/srv/app', 'public', null, $domain),
            new DevelopmentSite($this->router->id, '10.44.0.2', 'route-'.($index + 1).'-router', '', '', null, $domain, upstreamAddresses: ['192.168.6.9']));
    }
});

it('deduplicates shared tuples and keeps them when another Route survives', function (): void {
    $before = $this->policy->forSites($this->workload, $this->sites);
    $after = $this->policy->forSites($this->workload, $this->sites->where('domain', 'second.test'));
    expect($before)->toBe([['port' => 443, 'protocol' => 'tcp', 'source' => '192.168.6.2']])
        ->and($this->policy->changes($before, $after))->toBe([['port' => 443, 'protocol' => 'tcp', 'source' => '192.168.6.2', 'ensure' => 'present', 'model_name' => '808ffb08edc5788d']]);
});

it('withdraws the tuple only after its final Route disappears', function (): void {
    $prior = $this->policy->forSites($this->workload, $this->sites);
    expect($this->policy->changes($prior, []))->toBe([['port' => 443, 'protocol' => 'tcp', 'source' => '192.168.6.2', 'ensure' => 'absent', 'model_name' => '808ffb08edc5788d']]);
});

it('keeps distinct Router sources and excludes a colocated or WireGuard hop', function (): void {
    $second = Node::query()->create(['name' => 'second-router', 'public_ssh_host' => '192.0.2.3', 'lan_ip' => '192.168.6.3', 'user' => 'orbit']);
    $this->sites->push(new DevelopmentSite($second->id, '10.44.0.3', 'route-3-router', '', '', null, 'first.test', upstreamAddresses: ['192.168.6.9']),
        new DevelopmentSite($this->workload->id, '10.44.0.9', 'route-4-router', '', '', null, 'first.test', upstreamAddresses: ['192.168.6.9']),
        new DevelopmentSite($second->id, '10.44.0.3', 'route-5-router', '', '', null, 'first.test', upstreamAddresses: ['10.44.0.9']));
    expect(array_column($this->policy->forSites($this->workload, $this->sites), 'source'))->toBe(['192.168.6.2', '192.168.6.3']);
});

it('does not assign an identical LAN address on a Node outside this Route', function (): void {
    $other = Node::query()->create(['name' => 'other-workload', 'public_ssh_host' => '192.0.2.19', 'lan_ip' => '192.168.6.9', 'user' => 'orbit']);
    expect($this->policy->forSites($other, $this->sites))->toBe([]);
});

it('records a proven migration once and rejects a foreign Node result', function (): void {
    expect($this->policy->migrationPending($this->workload->id))->toBeTrue();
    $this->policy->recordMigrations([$this->workload->id], [$this->workload->id]);
    expect($this->policy->migrationPending($this->workload->fresh()->id))->toBeFalse()
        ->and(fn () => $this->policy->recordMigrations([$this->workload->id], [$this->router->id]))->toThrow(ResourceOperationException::class)
        ->and($this->policy->migrationPending($this->router->id))->toBeTrue();
});
