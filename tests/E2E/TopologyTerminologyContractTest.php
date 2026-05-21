<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;

pest()->group('e2e-topology-contract', 'e2e-topology-contract-operator-gateway-appdev');

it('uses operator topology names while preserving control aliases', function (): void {
    expect(E2ETopologyKind::Operator->value)->toBe('operator')
        ->and(E2ETopologyKind::OperatorGateway->value)->toBe('operator-gateway')
        ->and(E2ETopologyKind::OperatorGatewayAppdev->value)->toBe('operator-gateway-appdev')
        ->and(E2ETopologyKind::OperatorGatewayAppdevAppprod->value)->toBe('operator-gateway-appdev-appprod')
        ->and(E2ETopologyKind::Control)->toBe(E2ETopologyKind::Operator)
        ->and(E2ETopologyKind::ControlGateway)->toBe(E2ETopologyKind::OperatorGateway)
        ->and(E2ETopologyKind::ControlGatewayDev)->toBe(E2ETopologyKind::OperatorGatewayAppdev)
        ->and(E2ETopologyKind::ControlGatewayDevProd)->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprod)
        ->and(E2ETopologyKind::tryFromInput('control-gateway-dev'))->toBe(E2ETopologyKind::OperatorGatewayAppdev)
        ->and(E2ETopologyKind::tryFromInput('control-gateway-dev-prod'))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprod)
        ->and(E2ETopologyKind::OperatorGatewayAppdev->featureGroup())->toBe('e2e-feature-operator-gateway-appdev')
        ->and(E2ETopologyKind::OperatorGatewayAppdev->deprecatedFeatureGroups())->toContain('e2e-feature-control-gateway-dev');
});

it('supports operator aliases on the topology harness and lease', function (): void {
    $operator = fakeTopologyInstance('operator-vm');
    $gateway = fakeTopologyInstance('gateway-vm');
    $lease = fakeTopologyLease($operator, $gateway);

    $harness = new E2ETopologyHarness($lease, [
        'operator' => '/home/control/orbit-current',
        'control' => '/home/control/orbit-current',
        'gateway' => '/home/orbit/orbit-current',
    ]);

    expect($lease->operator())->toBe($operator)
        ->and($lease->control())->toBe($operator)
        ->and($harness->instance('operator'))->toBe($operator)
        ->and($harness->instance('control'))->toBe($operator)
        ->and($harness->checkout('operator'))->toBe('/home/control/orbit-current')
        ->and($harness->checkout('control'))->toBe('/home/control/orbit-current');
});

it('uses operator topology names for docker images while preserving control role fixtures', function (): void {
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGatewayAppdev, 'control'))
        ->toBe('orbit-e2e-topology:operator_gateway_app-dev-control-current')
        ->and($provider->imageNameFor(E2ETopologyKind::OperatorGatewayAppdevAppprod, 'gateway'))
        ->toBe('orbit-e2e-topology:operator_gateway_app-dev_app-prod-gateway-current')
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdev))
        ->toBe(['control', 'gateway', 'dev']);
});

function fakeTopologyLease(E2EInstance $operator, ?E2EInstance $gateway = null): E2ETopologyLease
{
    return new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGateway,
        control: $operator,
        gateway: $gateway,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/dev/null', '/dev/null'),
        rebuild: fn () => ['instances' => ['control' => $operator, 'gateway' => $gateway], 'snapshotReset' => null],
    );
}

function fakeTopologyInstance(string $name): E2EInstance
{
    return new class($name) implements E2EInstance
    {
        public function __construct(
            private string $name,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            throw new RuntimeException('Not used in topology terminology tests.');
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            throw new RuntimeException('Not used in topology terminology tests.');
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '127.0.0.1';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}
