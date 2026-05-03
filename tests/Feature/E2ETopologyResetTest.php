<?php

declare(strict_types=1);

use Mockery as m;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\E2ETopologyLease;
use Tests\E2E\Support\SshKeyPair;

afterEach(function (): void {
    m::close();
});

it('cleanup is idempotent', function (): void {
    $control = m::mock(E2EInstance::class);
    $control->shouldReceive('delete')->once();

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: $control,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: fn () => [],
    );

    $lease->cleanup();
    $lease->cleanup();
});

it('reset calls cleanup and acquires fresh instances', function (): void {
    $oldControl = m::mock(E2EInstance::class);
    $oldControl->shouldReceive('delete')->once();

    $newControl = m::mock(E2EInstance::class);

    $callCount = 0;
    $rebuild = function () use ($newControl, &$callCount): array {
        $callCount++;

        return ['control' => $newControl];
    };

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: $oldControl,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: $rebuild,
    );

    $lease->reset();

    expect($callCount)->toBe(1);
});

it('returns new instances after reset', function (): void {
    $oldControl = m::mock(E2EInstance::class);
    $oldGateway = m::mock(E2EInstance::class);
    $oldDev = m::mock(E2EInstance::class);
    $oldProd = m::mock(E2EInstance::class);

    $oldControl->shouldReceive('delete')->once();
    $oldGateway->shouldReceive('delete')->once();
    $oldDev->shouldReceive('delete')->once();
    $oldProd->shouldReceive('delete')->once();

    $newControl = m::mock(E2EInstance::class);
    $newGateway = m::mock(E2EInstance::class);
    $newDev = m::mock(E2EInstance::class);
    $newProd = m::mock(E2EInstance::class);

    $rebuild = fn (): array => [
        'control' => $newControl,
        'gateway' => $newGateway,
        'dev' => $newDev,
        'prod' => $newProd,
    ];

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDevProd,
        control: $oldControl,
        gateway: $oldGateway,
        dev: $oldDev,
        prod: $oldProd,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: $rebuild,
    );

    $lease->reset();

    expect($lease->control())->toBe($newControl)
        ->and($lease->gateway())->toBe($newGateway)
        ->and($lease->devApp())->toBe($newDev)
        ->and($lease->prodApp())->toBe($newProd);
});

it('throws when ORBIT_E2E_TOPOLOGY_RESET is snapshot-restore', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_RESET');
    putenv('ORBIT_E2E_TOPOLOGY_RESET=snapshot-restore');

    try {
        $control = m::mock(E2EInstance::class);

        $lease = new E2ETopologyLease(
            kind: E2ETopologyKind::Control,
            control: $control,
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
            rebuild: fn () => [],
        );

        expect(fn () => $lease->reset())
            ->toThrow(RuntimeException::class, 'snapshot-restore reset strategy is not implemented yet');
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TOPOLOGY_RESET');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_RESET={$previous}");
        }
    }
});

it('falls back to fresh-clone for unknown strategy values', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_RESET');
    putenv('ORBIT_E2E_TOPOLOGY_RESET=unknown-strategy');

    try {
        $oldControl = m::mock(E2EInstance::class);
        $oldControl->shouldReceive('delete')->once();

        $newControl = m::mock(E2EInstance::class);

        $rebuild = fn (): array => ['control' => $newControl];

        $lease = new E2ETopologyLease(
            kind: E2ETopologyKind::Control,
            control: $oldControl,
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
            rebuild: $rebuild,
        );

        $lease->reset();

        expect($lease->control())->toBe($newControl);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TOPOLOGY_RESET');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_RESET={$previous}");
        }
    }
});
