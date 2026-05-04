<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Tests\E2E\Support\DockerTopologyBuilder;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('builds control-gateway prepared images through transient docker resources', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-control-gateway'" => Process::result(),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'orbit-e2e-build-control-gateway-control' --network 'orbit-e2e-build-control-gateway' --ip '10.6.0.3' 'orbit-e2e-topology-runtime:current'" => Process::result(output: "control-id\n"),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'orbit-e2e-build-control-gateway-gateway' --network 'orbit-e2e-build-control-gateway' --ip '10.6.0.2' 'orbit-e2e-topology-runtime:current'" => Process::result(output: "gateway-id\n"),
        "docker exec --user 'control' 'orbit-e2e-build-control-gateway-control' sh -lc *migrate*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-build-control-gateway-gateway' sh -lc *migrate*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-build-control-gateway-gateway' sh -lc *bootstrap-gateway-local*" => Process::result(),
        "docker exec 'orbit-e2e-build-control-gateway-gateway' sh -lc *tinker*" => Process::result(),
        "docker exec 'orbit-e2e-build-control-gateway-gateway' sh -lc *cat*" => Process::result(),
        "docker exec 'orbit-e2e-build-control-gateway-gateway' sh -lc *nohup*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-control-gateway-control' sh -lc *curl*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-control-gateway-control' sh -lc *tinker*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-control-gateway-control' sh -lc *gateway:add*" => Process::result(),
        "docker commit --change * 'orbit-e2e-build-control-gateway-control' 'orbit-e2e-topology:control-gateway-control-current'" => Process::result(),
        "docker commit --change * 'orbit-e2e-build-control-gateway-gateway' 'orbit-e2e-topology:control-gateway-gateway-current'" => Process::result(),
        "docker rm -f 'orbit-e2e-build-control-gateway-control' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'orbit-e2e-build-control-gateway-gateway' >/dev/null 2>&1 || true" => Process::result(),
        "docker network rm 'orbit-e2e-build-control-gateway' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    expect($manifest)->toBe([
        ['role' => 'control', 'container' => 'orbit-e2e-build-control-gateway-control', 'image' => 'orbit-e2e-topology:control-gateway-control-current'],
        ['role' => 'gateway', 'container' => 'orbit-e2e-build-control-gateway-gateway', 'image' => 'orbit-e2e-topology:control-gateway-gateway-current'],
    ]);

    Process::assertRan("docker commit --change 'CMD [\"sleep\", \"infinity\"]' 'orbit-e2e-build-control-gateway-control' 'orbit-e2e-topology:control-gateway-control-current'");
    Process::assertRan("docker network rm 'orbit-e2e-build-control-gateway' >/dev/null 2>&1 || true");
});

it('uses the configured instance prefix for transient resources but stable image tags', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'ci-foo-build-control-gateway'" => Process::result(),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'ci-foo-build-control-gateway-control' --network 'ci-foo-build-control-gateway' --ip '10.6.0.3' 'orbit-e2e-topology-runtime:current'" => Process::result(output: "control-id\n"),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'ci-foo-build-control-gateway-gateway' --network 'ci-foo-build-control-gateway' --ip '10.6.0.2' 'orbit-e2e-topology-runtime:current'" => Process::result(output: "gateway-id\n"),
        'docker exec --user *' => Process::result(),
        'docker exec *' => Process::result(),
        "docker commit --change * 'ci-foo-build-control-gateway-control' 'orbit-e2e-topology:control-gateway-control-current'" => Process::result(),
        "docker commit --change * 'ci-foo-build-control-gateway-gateway' 'orbit-e2e-topology:control-gateway-gateway-current'" => Process::result(),
        "docker rm -f 'ci-foo-build-control-gateway-control' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'ci-foo-build-control-gateway-gateway' >/dev/null 2>&1 || true" => Process::result(),
        "docker network rm 'ci-foo-build-control-gateway' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    withE2EEnvironment(['ORBIT_E2E_INSTANCE_PREFIX'], [
        'ORBIT_E2E_INSTANCE_PREFIX' => 'ci-foo',
    ], function (): void {
        $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
            ->build(E2ETopologyKind::ControlGateway);

        expect($manifest)->toBe([
            ['role' => 'control', 'container' => 'ci-foo-build-control-gateway-control', 'image' => 'orbit-e2e-topology:control-gateway-control-current'],
            ['role' => 'gateway', 'container' => 'ci-foo-build-control-gateway-gateway', 'image' => 'orbit-e2e-topology:control-gateway-gateway-current'],
        ]);
    });
});
