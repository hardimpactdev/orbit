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

pest()->group('e2e-topology-contract', 'e2e-topology-contract-operator_gateway_app-dev');

it('uses operator topology names and accepted input aliases', function (): void {
    expect(E2ETopologyKind::Operator->value)->toBe('operator')
        ->and(E2ETopologyKind::OperatorGateway->value)->toBe('operator_gateway')
        ->and(E2ETopologyKind::OperatorGatewayAppdev->value)->toBe('operator_gateway_app-dev')
        ->and(E2ETopologyKind::OperatorGatewayAppdevAppprod->value)->toBe('operator_gateway_app-dev_app-prod')
        ->and(E2ETopologyKind::OperatorGatewayAgent->value)->toBe('operator_gateway_agent')
        ->and(E2ETopologyKind::OperatorGatewayAppdevWebsocket->value)->toBe('operator_gateway_app-dev_websocket')
        ->and(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket->value)->toBe('operator_gateway_app-dev_app-prod_websocket')
        ->and(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket->value)->toBe('operator_gateway_app-dev_app-prod_agent_websocket')
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-dev'))->toBe(E2ETopologyKind::OperatorGatewayAppdev)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-dev-prod'))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprod)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-agent'))->toBe(E2ETopologyKind::OperatorGatewayAgent)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-dev-websocket'))->toBe(E2ETopologyKind::OperatorGatewayAppdevWebsocket)
        ->and(E2ETopologyKind::OperatorGatewayAppdev->featureGroup())->toBe('e2e-feature-operator_gateway_app-dev')
        ->and(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket->featureGroup())->toBe('e2e-feature-operator_gateway_app-dev_app-prod_agent_websocket')
        ->and(E2ETopologyKind::OperatorGatewayAppdev->deprecatedFeatureGroups())->toContain('e2e-feature-operator-gateway-dev');
});

it('uses operator on the topology harness and lease', function (): void {
    $operator = fakeTopologyInstance('operator-vm');
    $gateway = fakeTopologyInstance('gateway-vm');
    $lease = fakeTopologyLease($operator, $gateway);

    $harness = new E2ETopologyHarness($lease, [
        'operator' => '/home/orbit/orbit-current',
        'gateway' => '/home/orbit/orbit-current',
    ]);

    expect($lease->operator())->toBe($operator)
        ->and($harness->instance('operator'))->toBe($operator)
        ->and($harness->checkout('operator'))->toBe('/home/orbit/orbit-current');

    $harness->setCheckouts(['operator' => '/home/orbit/orbit-next']);

    expect($harness->checkout('operator'))->toBe('/home/orbit/orbit-next');
});

it('uses operator topology names for docker images', function (): void {
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGatewayAppdev, 'operator'))
        ->toBe('orbit-e2e:operator_base')
        ->and($provider->imageNameFor(E2ETopologyKind::OperatorGatewayAppdevAppprod, 'gateway'))
        ->toBe('orbit-e2e:gateway_base')
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdev))
        ->toBe(['operator', 'gateway', 'dev']);
});

function fakeTopologyLease(E2EInstance $operator, ?E2EInstance $gateway = null): E2ETopologyLease
{
    return new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGateway,
        operator: $operator,
        gateway: $gateway,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/dev/null', '/dev/null'),
        rebuild: fn () => ['instances' => ['operator' => $operator, 'gateway' => $gateway], 'snapshotReset' => null],
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
