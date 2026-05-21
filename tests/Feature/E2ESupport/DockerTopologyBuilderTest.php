<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('builds operator-gateway prepared images through transient docker resources', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-operator-gateway'" => Process::result(),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'orbit-e2e-build-operator-gateway-control' --network 'orbit-e2e-build-operator-gateway' --ip '10.6.0.3' 'orbit-e2e-topology-runtime:current'" => Process::result(output: "control-id\n"),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'orbit-e2e-build-operator-gateway-gateway' --network 'orbit-e2e-build-operator-gateway' --ip '10.6.0.2' 'orbit-e2e-topology-runtime:current'" => Process::result(output: "gateway-id\n"),
        "docker exec --user 'control' 'orbit-e2e-build-operator-gateway-control' sh -lc *migrate*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-build-operator-gateway-gateway' sh -lc *migrate*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-build-operator-gateway-gateway' sh -lc *bootstrap-gateway-local*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-build-operator-gateway-gateway' sh -lc *doctor*--family=schedule*" => Process::result(),
        "docker exec 'orbit-e2e-build-operator-gateway-gateway' sh -lc *tinker*" => Process::result(),
        "docker exec 'orbit-e2e-build-operator-gateway-gateway' sh -lc *cat*" => Process::result(),
        "docker exec 'orbit-e2e-build-operator-gateway-gateway' sh -lc 'systemctl stop caddy >/dev/null 2>&1 || true'" => Process::result(),
        "docker exec 'orbit-e2e-build-operator-gateway-gateway' sh -lc 'if [ -f /home/orbit/.ssh/id_ed25519 ]; then install -d -m 700 /root/.ssh && cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /home/orbit/.ssh/id_ed25519.pub ]; then cp /home/orbit/.ssh/id_ed25519.pub /root/.ssh/id_ed25519.pub; fi; fi'" => Process::result(),
        "docker exec 'orbit-e2e-build-operator-gateway-gateway' sh -lc *nohup*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-operator-gateway-control' sh -lc *curl*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-operator-gateway-control' sh -lc *tinker*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-operator-gateway-control' sh -lc *gateway:add*" => Process::result(),
        "docker commit --change * 'orbit-e2e-build-operator-gateway-control' 'orbit-e2e-topology:operator_gateway-control-current'" => Process::result(),
        "docker commit --change * 'orbit-e2e-build-operator-gateway-gateway' 'orbit-e2e-topology:operator_gateway-gateway-current'" => Process::result(),
        "docker rm -f 'orbit-e2e-build-operator-gateway-control' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'orbit-e2e-build-operator-gateway-gateway' >/dev/null 2>&1 || true" => Process::result(),
        "docker network rm 'orbit-e2e-build-operator-gateway' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    expect($manifest)->toBe([
        ['role' => 'control', 'container' => 'orbit-e2e-build-operator-gateway-control', 'image' => 'orbit-e2e-topology:operator_gateway-control-current'],
        ['role' => 'gateway', 'container' => 'orbit-e2e-build-operator-gateway-gateway', 'image' => 'orbit-e2e-topology:operator_gateway-gateway-current'],
    ]);

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker commit')
        && $process->timeout === 600
        && str_contains($process->command, 'CMD ["/usr/local/bin/orbit-e2e-container"]')
        && str_contains($process->command, 'org.orbit.e2e.topology-mode=legacy-retarget')
        && str_contains($process->command, 'org.orbit.e2e.cert-san-set=IP:10.6.0.2')
        && ! str_contains($process->command, 'org.orbit.e2e.cert-san-set=DNS:gateway,IP:10.6.0.2')
        && str_contains($process->command, "'orbit-e2e-build-operator-gateway-control'")
        && str_contains($process->command, "'orbit-e2e-topology:operator_gateway-control-current'"));
    Process::assertRan("docker network rm 'orbit-e2e-build-operator-gateway' >/dev/null 2>&1 || true");
});

it('seeds gateway to app node ssh access for remote shell feature tests', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-operator-gateway-appdev'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *systemctl stop caddy*' => Process::result(),
        'docker exec *nohup*' => Process::result(),
        'docker exec --user *curl*' => Process::result(),
        'docker exec --user *gateway:add*' => Process::result(),
        'docker exec *ssh-keygen*' => Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n"),
        'docker exec *id_ed25519*' => Process::result(),
        'docker exec *cat*' => Process::result(),
        "docker exec 'orbit-e2e-build-operator-gateway-appdev-gateway' sh -lc 'if [ -f /home/orbit/.ssh/id_ed25519 ]; then install -d -m 700 /root/.ssh && cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /home/orbit/.ssh/id_ed25519.pub ]; then cp /home/orbit/.ssh/id_ed25519.pub /root/.ssh/id_ed25519.pub; fi; fi'" => Process::result(),
        "docker exec 'orbit-e2e-build-operator-gateway-appdev-dev' sh -lc *authorized_keys*" => Process::result(),
        'docker exec --user *ssh-keyscan*' => Process::result(),
        'docker exec --user *bake-app-node*' => Process::result(),
        'docker commit --change *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGatewayDev);

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'ssh-keygen -t ed25519')
        && str_contains($process->command, 'cat ~/.ssh/id_ed25519.pub'));
    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'authorized_keys')
        && str_contains($process->command, 'ssh-ed25519 AAAATEST orbit-e2e-gateway'));
});

it('uses the configured instance prefix for transient resources but stable image tags', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'ci-foo-build-operator-gateway'" => Process::result(),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'ci-foo-build-operator-gateway-control' --network 'ci-foo-build-operator-gateway' --ip '10.6.0.3' 'orbit-e2e-topology-runtime:current'" => Process::result(output: "control-id\n"),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'ci-foo-build-operator-gateway-gateway' --network 'ci-foo-build-operator-gateway' --ip '10.6.0.2' 'orbit-e2e-topology-runtime:current'" => Process::result(output: "gateway-id\n"),
        'docker exec --user *' => Process::result(),
        'docker exec *' => Process::result(),
        "docker commit --change * 'ci-foo-build-operator-gateway-control' 'orbit-e2e-topology:operator_gateway-control-current'" => Process::result(),
        "docker commit --change * 'ci-foo-build-operator-gateway-gateway' 'orbit-e2e-topology:operator_gateway-gateway-current'" => Process::result(),
        "docker rm -f 'ci-foo-build-operator-gateway-control' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'ci-foo-build-operator-gateway-gateway' >/dev/null 2>&1 || true" => Process::result(),
        "docker network rm 'ci-foo-build-operator-gateway' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    withE2EEnvironment(['ORBIT_E2E_INSTANCE_PREFIX'], [
        'ORBIT_E2E_INSTANCE_PREFIX' => 'ci-foo',
    ], function (): void {
        $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
            ->build(E2ETopologyKind::ControlGateway);

        expect($manifest)->toBe([
            ['role' => 'control', 'container' => 'ci-foo-build-operator-gateway-control', 'image' => 'orbit-e2e-topology:operator_gateway-control-current'],
            ['role' => 'gateway', 'container' => 'ci-foo-build-operator-gateway-gateway', 'image' => 'orbit-e2e-topology:operator_gateway-gateway-current'],
        ]);
    });
});

it('bakes dns alias topology registry data and mode-specific image tags', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-operator-gateway-appdev-appprod'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *systemctl stop caddy*' => Process::result(),
        'docker exec *nohup*' => Process::result(),
        'docker exec --user *curl*' => Process::result(),
        'docker exec --user *gateway:add*' => Process::result(),
        'docker exec *ssh-keygen*' => Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n"),
        'docker exec *id_ed25519*' => Process::result(),
        'docker exec *cat*' => Process::result(),
        'docker exec *authorized_keys*' => Process::result(),
        'docker exec --user *ssh-keyscan*' => Process::result(),
        'docker exec --user *bake-app-node*' => Process::result(),
        'docker commit --change *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGatewayDevProd, 'dns-alias');

    expect($manifest[0]['image'])->toBe('orbit-e2e-topology:operator_gateway_app-dev_app-prod-control-dns-alias-current');

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'orbit:internal:bake-app-node app-dev-1')
        && str_contains($process->command, '--role=app-dev')
        && str_contains($process->command, '--host=dev')
        && str_contains($process->command, '--gateway-endpoint=gateway')
        && ! str_contains($process->command, '--environment=development')
        && ! str_contains($process->command, '--host=10.6.0.4'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'LocalGatewaySettings::current()')
        && str_contains($process->command, 'https://gateway'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker commit')
        && str_contains($process->command, 'org.orbit.e2e.topology-mode=dns-alias')
        && str_contains($process->command, 'org.orbit.e2e.cert-san-set=DNS:gateway,IP:10.6.0.2'));
});

it('bakes ingress docker topology registry data without dev or agent roles', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-operator-gateway-appprod-ingress'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *systemctl stop caddy*' => Process::result(),
        'docker exec *nohup*' => Process::result(),
        'docker exec --user *curl*' => Process::result(),
        'docker exec --user *gateway:add*' => Process::result(),
        'docker exec *ssh-keygen*' => Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n"),
        'docker exec *id_ed25519*' => Process::result(),
        'docker exec *cat*' => Process::result(),
        'docker exec *authorized_keys*' => Process::result(),
        'docker exec --user *ssh-keyscan*' => Process::result(),
        'docker exec --user *bake-ingress-node*' => Process::result(),
        'docker exec --user *bake-app-node*' => Process::result(),
        'docker commit --change *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppprodIngress, 'dns-alias');

    expect(array_column($manifest, 'role'))->toBe(['control', 'gateway', 'prod', 'ingress'])
        ->and($manifest[0]['image'])->toBe('orbit-e2e-topology:operator_gateway_app-prod_ingress-control-dns-alias-current');

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker run -d')
        && str_contains($process->command, "--name 'orbit-e2e-build-operator-gateway-appprod-ingress-ingress'")
        && str_contains($process->command, "--network-alias 'ingress'")
        && str_contains($process->command, "--ip '10.6.0.8'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2')
        && str_contains($process->command, '--skip-wireguard-install'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'ssh-keyscan -T 2')
        && str_contains($process->command, '10.6.0.8'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'orbit:internal:bake-ingress-node edge-1')
        && str_contains($process->command, '--host=ingress')
        && str_contains($process->command, '--host-key-host=')
        && str_contains($process->command, '10.6.0.8')
        && str_contains($process->command, '10.6.0.7')
        && str_contains($process->command, '--wireguard-address=10.6.0.7'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'orbit:internal:bake-app-node app-prod-1')
        && str_contains($process->command, '--role=app-prod')
        && str_contains($process->command, '--host=prod')
        && str_contains($process->command, '--host-key-host=')
        && str_contains($process->command, '10.6.0.5')
        && ! str_contains($process->command, '--environment=production')
        && str_contains($process->command, '--ingress-node=edge-1'));

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && (str_contains($process->command, 'app-dev-1') || str_contains($process->command, 'agent-1')));
});
