<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('defines the Docker topology host PHP 8.4 CLI baseline without ad hoc helper binaries', function (): void {
    $dockerfile = file_get_contents(base_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('FROM ubuntu:24.04')
        ->toContain('software-properties-common')
        ->toContain('add-apt-repository ppa:ondrej/php -y')
        ->toContain('apt-get purge -y --auto-remove software-properties-common')
        ->toContain('php8.4-cli')
        ->toContain('php8.4-mbstring')
        ->toContain('php8.4-curl')
        ->toContain('php8.4-sqlite3')
        ->toContain('php8.4-xml')
        ->toContain('update-alternatives --set php /usr/bin/php8.4')
        ->toContain('php --version')
        ->toContain('PHP 8.4.')
        ->toContain('pdo_sqlite')
        ->toContain('openssl')
        ->toContain('curl')
        ->toContain('mbstring')
        ->toContain('json')
        ->not->toContain('python3');

    expect(preg_match('/(?:^|\s)sqlite3(?:\s|\\\\|$)/m', $dockerfile))->toBe(0);
});

it('starts Docker build topology nodes with the host Docker socket and runtime container context', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::Control);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain("--volume '/var/run/docker.sock:/var/run/docker.sock'")
        ->toContain("--env 'ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-build-operator'")
        ->toContain("--env 'ORBIT_RUNTIME_CONTAINER=orbit-e2e-build-operator-control-orbit-runtime'")
        ->toContain("docker run -d --restart unless-stopped --name 'orbit-e2e-build-operator-control-orbit-runtime'")
        ->toContain("--network 'container:orbit-e2e-build-operator-control'")
        ->toContain("--env 'ORBIT_SOURCE_PATH=/home/control/orbit'")
        ->toContain('composer install --no-interaction --prefer-dist --optimize-autoloader');
});

it('fails clearly when the orbit runtime sibling image is missing during docker topology preparation', function (): void {
    Process::fake(function ($process) {
        if ($process->command === "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null") {
            return Process::result();
        }

        if ($process->command === "docker image inspect 'orbit-runtime:current' >/dev/null") {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    expect(fn () => (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, 'Docker Orbit runtime image is missing');
});

it('builds Docker topology state through the host orbit launcher', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    $setup = implode("\n", $commands);
    $controlRuntimeStart = strpos($setup, "docker run -d --restart unless-stopped --name 'orbit-e2e-build-operator_gateway-control-orbit-runtime'");
    $controlMigrate = strpos($setup, "docker exec --user 'control' 'orbit-e2e-build-operator_gateway-control' sh -lc 'cd /home/control/orbit && orbit migrate --force'");

    expect($controlRuntimeStart)->toBeInt()
        ->and($controlMigrate)->toBeInt()
        ->and($controlRuntimeStart)->toBeLessThan($controlMigrate);

    expect($setup)
        ->toContain('cd /home/control/orbit && orbit migrate --force')
        ->toContain('cd /home/orbit/orbit && orbit migrate --force')
        ->toContain('cd /home/orbit/orbit && orbit orbit:internal:bootstrap-gateway-local gateway 10.6.0.2 --skip-runtime-install --skip-wireguard-install')
        ->toContain('sudo -iu orbit env ORBIT_RUNTIME_CONTAINER="${ORBIT_RUNTIME_CONTAINER:-}" ORBIT_E2E_DOCKER_NETWORK="${ORBIT_E2E_DOCKER_NETWORK:-}" bash -lc')
        ->toContain('cd /home/control/orbit && orbit gateway:add 10.6.0.2 --json')
        ->not->toContain('php artisan migrate --force');
});

it('starts the build gateway scheduler before schedule doctor verification', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    $setup = implode("\n", $commands);
    $bootstrap = strpos($setup, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2');
    $scheduler = strpos($setup, "docker exec --detach --workdir '/home/orbit/orbit' 'orbit-e2e-build-operator_gateway-gateway-orbit-runtime' orbit orbit-scheduler");
    $doctor = strpos($setup, 'orbit doctor --node=gateway --family=schedule --restore --json');

    expect($bootstrap)->toBeInt()
        ->and($scheduler)->toBeInt()
        ->and($doctor)->toBeInt()
        ->and($bootstrap)->toBeLessThan($scheduler)
        ->and($scheduler)->toBeLessThan($doctor);
});

it('keeps the build gateway runtime marked without starting services before migration', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    $gatewayRuntimeStart = collect($commands)
        ->first(fn (string $command): bool => str_contains($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-build-operator_gateway-gateway-orbit-runtime'"));

    expect($gatewayRuntimeStart)->toBeString()
        ->and($gatewayRuntimeStart)->toContain('ORBIT_IS_GATEWAY=1')
        ->and($gatewayRuntimeStart)->toContain("'orbit-runtime:current' tail -f /dev/null");
});

it('normalizes persisted gateway orbit state ownership before committing prepared images', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    $persist = collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec -i 'orbit-e2e-build-operator_gateway-gateway' tar -C '/home/orbit/orbit' -xf -"));
    $ownership = collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec 'orbit-e2e-build-operator_gateway-gateway'")
        && str_contains($command, 'chown -R orbit:orbit')
        && str_contains($command, '/home/orbit/orbit/storage/app/orbit'));
    $commit = collect($commands)->first(fn (string $command): bool => str_contains($command, 'docker commit')
        && str_contains($command, "'orbit-e2e-build-operator_gateway-gateway'")
        && str_contains($command, 'operator_gateway-gateway-current'));

    expect($persist)->toBeString()
        ->and($ownership)->toBeString()
        ->and($commit)->toBeString()
        ->and(array_search($persist, $commands, strict: true))->toBeLessThan(array_search($ownership, $commands, strict: true))
        ->and(array_search($ownership, $commands, strict: true))->toBeLessThan(array_search($commit, $commands, strict: true));
});

it('commits Docker build topology images from node image-layer state instead of mounted home volumes', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    $setup = implode("\n", $commands);
    $controlSync = strpos($setup, "docker exec 'orbit-e2e-build-operator_gateway-control-orbit-runtime' tar -C '/home/control/orbit' -cf - . | docker exec -i 'orbit-e2e-build-operator_gateway-control' tar -C '/home/control/orbit' -xf -");
    $controlCommit = strpos($setup, "docker commit --change 'CMD [\"/usr/local/bin/orbit-e2e-container\"]' --change 'LABEL org.orbit.e2e.topology-mode=legacy-retarget' --change 'LABEL org.orbit.e2e.kind=operator_gateway' --change 'LABEL org.orbit.e2e.role=control'");
    $gatewaySync = strpos($setup, "docker exec 'orbit-e2e-build-operator_gateway-gateway-orbit-runtime' tar -C '/home/orbit/orbit' -cf - . | docker exec -i 'orbit-e2e-build-operator_gateway-gateway' tar -C '/home/orbit/orbit' -xf -");
    $gatewayCommit = strpos($setup, "docker commit --change 'CMD [\"/usr/local/bin/orbit-e2e-container\"]' --change 'LABEL org.orbit.e2e.topology-mode=legacy-retarget' --change 'LABEL org.orbit.e2e.kind=operator_gateway' --change 'LABEL org.orbit.e2e.role=gateway'");

    expect($setup)
        ->not->toContain("--mount 'type=volume,src=orbit-e2e-build-operator_gateway-control-home-control,dst=/home/control'")
        ->not->toContain("--mount 'type=volume,src=orbit-e2e-build-operator_gateway-gateway-home-orbit,dst=/home/orbit'");

    expect($controlSync)->toBeInt()
        ->and($controlCommit)->toBeInt()
        ->and($controlSync)->toBeLessThan($controlCommit)
        ->and($gatewaySync)->toBeInt()
        ->and($gatewayCommit)->toBeInt()
        ->and($gatewaySync)->toBeLessThan($gatewayCommit);
});

it('does not use host PHP or host Caddy paths while building Docker gateway topology state', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('orbit tinker --execute=')
        ->toContain('orbit serve --host=')
        ->not->toContain('php artisan')
        ->not->toContain('php -S')
        ->not->toContain('nohup php')
        ->not->toContain('php -r')
        ->not->toContain('systemctl stop caddy');
});

it('defines downstream small topology role matrices without requiring future role runtime classes', function (): void {
    expect(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdev))->toBe(['control', 'gateway', 'dev'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevIngress))->toBe(['control', 'gateway', 'dev', 'ingress'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevWebsocket))->toBe(['control', 'gateway', 'dev', 'websocket'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevS3))->toBe(['control', 'gateway', 'dev', 's3'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevIngressWebsocketS3))->toBe(['control', 'gateway', 'dev', 'ingress', 'websocket', 's3']);
});

it('accepts client named aliases for downstream small topology fixtures', function (): void {
    expect(E2ETopologyKind::tryFromInput('client-gateway-appdev'))->toBe(E2ETopologyKind::OperatorGatewayAppdev)
        ->and(E2ETopologyKind::tryFromInput('client-gateway-appdev-ingress'))->toBe(E2ETopologyKind::OperatorGatewayAppdevIngress)
        ->and(E2ETopologyKind::tryFromInput('client-gateway-appdev-websocket'))->toBe(E2ETopologyKind::OperatorGatewayAppdevWebsocket)
        ->and(E2ETopologyKind::tryFromInput('client-gateway-appdev-s3'))->toBe(E2ETopologyKind::OperatorGatewayAppdevS3)
        ->and(E2ETopologyKind::tryFromInput('client-gateway-appdev-ingress-websocket-s3'))->toBe(E2ETopologyKind::OperatorGatewayAppdevIngressWebsocketS3);
});

it('seeds appdev docker topology with database role and Redis expectation for future websocket tests', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdev);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('NodeRoleName::Database')
        ->toContain('NodeTool::query()->updateOrCreate')
        ->toContain('redis')
        ->toContain('expected_state')
        ->toContain('running');
});

it('bakes downstream future-role topology placement without websocket or s3 runtime commands', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdevIngressWebsocketS3, 'dns-alias');

    expect(array_column($manifest, 'role'))->toBe(['control', 'gateway', 'dev', 'ingress', 'websocket', 's3'])
        ->and($manifest[4]['image'])->toBe('orbit-e2e-topology:operator_gateway_app-dev_ingress_websocket_s3-websocket-dns-alias-current')
        ->and($manifest[5]['image'])->toBe('orbit-e2e-topology:operator_gateway_app-dev_ingress_websocket_s3-s3-dns-alias-current');

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain("--name 'orbit-e2e-build-operator_gateway_app-dev_ingress_websocket_s3-websocket'")
        ->toContain("--name 'orbit-e2e-build-operator_gateway_app-dev_ingress_websocket_s3-s3'")
        ->toContain("--network-alias 'websocket'")
        ->toContain("--network-alias 's3'")
        ->not->toContain('bake-websocket')
        ->not->toContain('bake-s3')
        ->not->toContain('reverb')
        ->not->toContain('rustfs');
});

it('builds operator_gateway prepared images through transient docker resources', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-operator_gateway'" => Process::result(),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'orbit-e2e-build-operator_gateway-control' *" => Process::result(output: "control-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-build-operator_gateway-control-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'orbit-e2e-build-operator_gateway-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-build-operator_gateway-gateway-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        'docker exec *mkdir -p*' => Process::result(),
        'docker exec *tar -C*' => Process::result(),
        'docker exec *chown -R orbit:orbit*' => Process::result(),
        'docker exec --env *composer install*' => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-operator_gateway-control' sh -lc *migrate*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-build-operator_gateway-gateway' sh -lc *migrate*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-build-operator_gateway-gateway' sh -lc *bootstrap-gateway-local*" => Process::result(),
        "docker exec --detach --workdir '/home/orbit/orbit' 'orbit-e2e-build-operator_gateway-gateway-orbit-runtime' orbit orbit-scheduler" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-build-operator_gateway-gateway' sh -lc *doctor*--family=schedule*" => Process::result(),
        "docker exec 'orbit-e2e-build-operator_gateway-gateway' sh -lc *tinker*" => Process::result(),
        "docker exec 'orbit-e2e-build-operator_gateway-gateway' sh -lc *cat*" => Process::result(),
        "docker exec 'orbit-e2e-build-operator_gateway-gateway' sh -lc 'if [ -f /home/orbit/.ssh/id_ed25519 ]; then install -d -m 700 /root/.ssh && cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /home/orbit/.ssh/id_ed25519.pub ]; then cp /home/orbit/.ssh/id_ed25519.pub /root/.ssh/id_ed25519.pub; fi; fi'" => Process::result(),
        "docker exec 'orbit-e2e-build-operator_gateway-gateway' sh -lc *sudo docker exec*id_ed25519*" => Process::result(),
        "docker exec 'orbit-e2e-build-operator_gateway-gateway' sh -lc *orbit serve*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-operator_gateway-control' sh -lc *curl*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-operator_gateway-control' sh -lc *tinker*" => Process::result(),
        "docker exec --user 'control' 'orbit-e2e-build-operator_gateway-control' sh -lc *gateway:add*" => Process::result(),
        "docker commit --change * 'orbit-e2e-build-operator_gateway-control' 'orbit-e2e-topology:operator_gateway-control-current'" => Process::result(),
        "docker commit --change * 'orbit-e2e-build-operator_gateway-gateway' 'orbit-e2e-topology:operator_gateway-gateway-current'" => Process::result(),
        "docker rm -f 'orbit-e2e-build-operator_gateway-control' 'orbit-e2e-build-operator_gateway-control-orbit-runtime' 'orbit-e2e-build-operator_gateway-control-orbit-caddy' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'orbit-e2e-build-operator_gateway-gateway' 'orbit-e2e-build-operator_gateway-gateway-orbit-runtime' 'orbit-e2e-build-operator_gateway-gateway-orbit-caddy' >/dev/null 2>&1 || true" => Process::result(),
        'docker volume rm -f *' => Process::result(),
        "docker network rm 'orbit-e2e-build-operator_gateway' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::ControlGateway);

    expect($manifest)->toBe([
        ['role' => 'control', 'container' => 'orbit-e2e-build-operator_gateway-control', 'image' => 'orbit-e2e-topology:operator_gateway-control-current'],
        ['role' => 'gateway', 'container' => 'orbit-e2e-build-operator_gateway-gateway', 'image' => 'orbit-e2e-topology:operator_gateway-gateway-current'],
    ]);

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker commit')
        && $process->timeout === 600
        && str_contains($process->command, 'CMD ["/usr/local/bin/orbit-e2e-container"]')
        && str_contains($process->command, 'org.orbit.e2e.topology-mode=legacy-retarget')
        && str_contains($process->command, 'org.orbit.e2e.cert-san-set=IP:10.6.0.2')
        && ! str_contains($process->command, 'org.orbit.e2e.cert-san-set=DNS:gateway,IP:10.6.0.2')
        && str_contains($process->command, "'orbit-e2e-build-operator_gateway-control'")
        && str_contains($process->command, "'orbit-e2e-topology:operator_gateway-control-current'"));
    Process::assertRan("docker network rm 'orbit-e2e-build-operator_gateway' >/dev/null 2>&1 || true");
});

it('seeds gateway to app node ssh access for remote shell feature tests', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-operator_gateway_app-dev'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec *mkdir -p*' => Process::result(),
        'docker exec *tar -C*' => Process::result(),
        'docker exec *chown -R orbit:orbit*' => Process::result(),
        'docker exec --env *composer install*' => Process::result(),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --detach *orbit-scheduler' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *orbit serve*' => Process::result(),
        'docker exec --user *curl*' => Process::result(),
        'docker exec --user *gateway:add*' => Process::result(),
        'docker exec *ssh-keygen*' => Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n"),
        'docker exec *id_ed25519*' => Process::result(),
        'docker exec *cat*' => Process::result(),
        "docker exec 'orbit-e2e-build-operator_gateway_app-dev-gateway' sh -lc 'if [ -f /home/orbit/.ssh/id_ed25519 ]; then install -d -m 700 /root/.ssh && cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /home/orbit/.ssh/id_ed25519.pub ]; then cp /home/orbit/.ssh/id_ed25519.pub /root/.ssh/id_ed25519.pub; fi; fi'" => Process::result(),
        "docker exec 'orbit-e2e-build-operator_gateway_app-dev-dev' sh -lc *authorized_keys*" => Process::result(),
        'docker exec --user *ssh-keyscan*' => Process::result(),
        'docker exec --user *bake-app-node*' => Process::result(),
        'docker commit --change *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker volume rm -f *' => Process::result(),
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
        "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'ci-foo-build-operator_gateway'" => Process::result(),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'ci-foo-build-operator_gateway-control' *" => Process::result(output: "control-id\n"),
        "docker run -d --restart unless-stopped --name 'ci-foo-build-operator_gateway-control-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'ci-foo-build-operator_gateway-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'ci-foo-build-operator_gateway-gateway-orbit-runtime' *" => Process::result(output: "runtime-id\n"),
        'docker exec --user *' => Process::result(),
        'docker exec *' => Process::result(),
        "docker commit --change * 'ci-foo-build-operator_gateway-control' 'orbit-e2e-topology:operator_gateway-control-current'" => Process::result(),
        "docker commit --change * 'ci-foo-build-operator_gateway-gateway' 'orbit-e2e-topology:operator_gateway-gateway-current'" => Process::result(),
        "docker rm -f 'ci-foo-build-operator_gateway-control' 'ci-foo-build-operator_gateway-control-orbit-runtime' 'ci-foo-build-operator_gateway-control-orbit-caddy' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'ci-foo-build-operator_gateway-gateway' 'ci-foo-build-operator_gateway-gateway-orbit-runtime' 'ci-foo-build-operator_gateway-gateway-orbit-caddy' >/dev/null 2>&1 || true" => Process::result(),
        'docker volume rm -f *' => Process::result(),
        "docker network rm 'ci-foo-build-operator_gateway' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    withE2EEnvironment(['ORBIT_E2E_INSTANCE_PREFIX'], [
        'ORBIT_E2E_INSTANCE_PREFIX' => 'ci-foo',
    ], function (): void {
        $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
            ->build(E2ETopologyKind::ControlGateway);

        expect($manifest)->toBe([
            ['role' => 'control', 'container' => 'ci-foo-build-operator_gateway-control', 'image' => 'orbit-e2e-topology:operator_gateway-control-current'],
            ['role' => 'gateway', 'container' => 'ci-foo-build-operator_gateway-gateway', 'image' => 'orbit-e2e-topology:operator_gateway-gateway-current'],
        ]);
    });
});

it('bakes dns alias topology registry data and mode-specific image tags', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-operator_gateway_app-dev_app-prod'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec *mkdir -p*' => Process::result(),
        'docker exec *tar -C*' => Process::result(),
        'docker exec *chown -R orbit:orbit*' => Process::result(),
        'docker exec --env *composer install*' => Process::result(),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --detach *orbit-scheduler' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *orbit serve*' => Process::result(),
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
        'docker volume rm -f *' => Process::result(),
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
        "docker image inspect 'orbit-runtime:current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-operator_gateway_app-prod_ingress'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec *mkdir -p*' => Process::result(),
        'docker exec *tar -C*' => Process::result(),
        'docker exec *chown -R orbit:orbit*' => Process::result(),
        'docker exec --env *composer install*' => Process::result(),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --detach *orbit-scheduler' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *orbit serve*' => Process::result(),
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
        'docker volume rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppprodIngress, 'dns-alias');

    expect(array_column($manifest, 'role'))->toBe(['control', 'gateway', 'prod', 'ingress'])
        ->and($manifest[0]['image'])->toBe('orbit-e2e-topology:operator_gateway_app-prod_ingress-control-dns-alias-current');

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker run -d')
        && str_contains($process->command, "--name 'orbit-e2e-build-operator_gateway_app-prod_ingress-ingress'")
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
