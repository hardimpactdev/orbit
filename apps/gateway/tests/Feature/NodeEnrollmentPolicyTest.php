<?php

declare(strict_types=1);

use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeEnrollmentPolicy;
use App\Domain\Shared\ResourceOperationException;
use Tests\Support\Fleet\FleetFixtures;

it('captures candidate storage and identity without discovering an account or changing stored identity', function (): void {
    $node = FleetFixtures::node('enrollment-pure-policy');
    $node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $prior = $node->refresh()->attributesToArray();
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);

    $spec = app(NodeEnrollmentPolicy::class)->prepare($node, new ProvisionNodeData(
        name: $node->name, publicSshHost: '192.0.2.200', orbitUser: 'candidate', wireguardIp: '10.44.0.200',
        settingsProvided: true, settings: new NodeSettingsData(apps: new NodeStorageAppsData('/srv/candidate-apps')),
    ));

    expect($spec['candidate']['user'])->toBe('candidate');
    expect($spec['candidate']['wireguard_ip'])->toBe('10.44.0.200');
    expect($node->fresh()->attributesToArray())->toBe($prior);
});

it('refuses a protected storage root before native work without resolving an account', function (): void {
    $node = FleetFixtures::node('enrollment-protected-storage');
    $node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);

    expect(fn () => app(NodeEnrollmentPolicy::class)->prepare($node, new ProvisionNodeData(
        name: $node->name, publicSshHost: '', settingsProvided: true,
        settings: new NodeSettingsData(apps: new NodeStorageAppsData('/etc/managed-apps')),
    )))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.settings_path_protected'));
});
