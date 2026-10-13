<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

/**
 * A host copy of one guest script under a temporary root: `/home/orbit` becomes `<root>/home` and
 * `/var/lib/orbit-e2e` becomes `<root>/state`, and each stub is an executable in `<root>/bin`, first on PATH.
 *
 * @param  array<string, string>  $stubs  command name => Bash body
 * @return array{root:string,script:string,home:string,state:string,commands:string,path:string}
 */
function guestScriptFixture(string $name, array $stubs = []): array
{
    $root = temporaryPath('orbit-guest-script-', 4);
    foreach (["{$root}/bin", "{$root}/home/.orbit", "{$root}/home/orbit/apps/gateway", "{$root}/home/orbit/apps/cli", "{$root}/state"] as $directory) {
        mkdir($directory, 0o700, true);
    }
    foreach ($stubs as $command => $body) {
        file_put_contents("{$root}/bin/{$command}", "#!/usr/bin/env bash\n".str_replace('__ROOT__', $root, $body)."\n");
        chmod("{$root}/bin/{$command}", 0o700);
    }
    $script = "{$root}/{$name}";
    file_put_contents($script, str_replace(
        ['/home/orbit', '/var/lib/orbit-e2e'],
        ["{$root}/home", "{$root}/state"],
        guestScriptSource($name),
    ));
    chmod($script, 0o700);

    return [
        'root' => $root,
        'script' => $script,
        'home' => "{$root}/home",
        'state' => "{$root}/state",
        'commands' => "{$root}/commands",
        'path' => "{$root}/bin:".getenv('PATH'),
    ];
}

/** A `sudo` that drops `-u USER --` and runs the rest as the caller. */
const GUEST_SUDO_STUB = 'shift 3; exec "$@"';

/** An `orbit` CLI stub that logs its arguments and answers `node:list` from `<root>/nodes.json`. */
function guestOrbitStub(string $root): string
{
    $orbit = "{$root}/home/orbit/apps/cli/orbit";
    file_put_contents($orbit, str_replace('__ROOT__', $root, <<<'BASH'
        #!/usr/bin/env bash
        printf 'orbit %s\n' "$*" >> '__ROOT__/commands'
        case "$1" in
          node:list) cat '__ROOT__/nodes.json' ;;
          *) printf '{"ok":true}\n' ;;
        esac
        BASH));
    chmod($orbit, 0o700);

    return $orbit;
}

/** @param array<string, mixed> $environment */
function runGuestScript(array $fixture, array $arguments, array $environment = []): Process
{
    $process = new Process(['bash', $fixture['script'], ...$arguments], env: ['PATH' => $fixture['path'], ...$environment], timeout: 30);
    $process->run();

    return $process;
}

/**
 * The fixture of `converge-app-dev.sh` and `converge-app-prod.sh`: SSH, host keys, and `php` stubbed.
 *
 * @return array{root:string,script:string,home:string,state:string,commands:string,path:string}
 */
function convergenceAppFixture(string $name): array
{
    $fixture = guestScriptFixture($name, [
        'sudo' => GUEST_SUDO_STUB,
        'ssh-keyscan' => "printf '%s\\n' 'host ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO'",
        'ssh-keygen' => "cat >/dev/null\nprintf '256 SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA host (ED25519)\\n'",
        'ssh' => "printf 'ssh %s\\n' \"\$*\" >> '__ROOT__/commands'\ncat >/dev/null",
        'php' => <<<'BASH'
            printf 'php %s\n' "$*" >> '__ROOT__/commands'
            if [[ "$*" == *'SELECT COUNT(*) FROM nodes'* ]]; then printf '%s\n' "${ORBIT_PHP_NODE_ACTIVE:-0}"; exit 0; fi
            [[ ",${ORBIT_PHP_FAIL:-}," == *',provision,'* && "$*" == *' orbit:node-provision '* ]] && exit 1
            exit 0
            BASH,
    ]);
    file_put_contents("{$fixture['home']}/.orbit/gateway.sqlite", 'fixture');

    return $fixture;
}

/**
 * A Gateway store with the given Nodes, as `converge-operator.sh provision` reads it.
 *
 * @param  array<string, string>  $nodes  name => status
 */
function operatorStore(string $home, array $nodes): void
{
    $pdo = new PDO("sqlite:{$home}/.orbit/gateway.sqlite");
    $pdo->exec('CREATE TABLE nodes (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)');
    $insert = $pdo->prepare('INSERT INTO nodes (name, status) VALUES (?, ?)');
    foreach ($nodes as $name => $status) {
        $insert->execute([$name, $status]);
    }
}

/** @return array{root:string,script:string,home:string,state:string,commands:string,path:string} */
function operatorFixture(): array
{
    $fixture = guestScriptFixture('converge-operator.sh', [
        'sudo' => GUEST_SUDO_STUB,
        'ssh-keyscan' => "printf 'ssh-keyscan %s\\n' \"\$*\" >> '__ROOT__/commands'\nprintf '%s\\n' 'host ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO'",
        'ssh-keygen' => "cat >/dev/null\nprintf '256 SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB host (ED25519)\\n'",
    ]);
    guestOrbitStub($fixture['root']);
    file_put_contents(
        "{$fixture['home']}/orbit/apps/gateway/artisan",
        "<?php\nfile_put_contents('{$fixture['commands']}', 'artisan '.implode(' ', array_slice(\$argv, 1)).\"\\n\", FILE_APPEND);\n",
    );

    return $fixture;
}

/** @return array{root:string,script:string,home:string,state:string,commands:string,path:string} */
function verifierFixture(): array
{
    $fixture = guestScriptFixture('verify-topology.sh', [
        'sudo' => GUEST_SUDO_STUB,
        'mountpoint' => 'exit "${MOUNTPOINT_EXIT:-0}"',
    ]);
    guestOrbitStub($fixture['root']);

    return $fixture;
}

/**
 * @param  array<string, list<string>>  $declared
 * @param  list<array<string, mixed>>  $listed
 */
function nodesRegistered(array $fixture, array $declared, array $listed): Process
{
    file_put_contents("{$fixture['root']}/nodes.json", json_encode(['nodes' => $listed], JSON_THROW_ON_ERROR));

    return runGuestScript($fixture, ['nodes.registered', str_repeat('a', 40), 'orbit-e2e-tst-9-aaaaaaaa-gateway', base64_encode(json_encode($declared, JSON_THROW_ON_ERROR))]);
}

function declaredNodes(): array
{
    return ['gateway' => ['gateway', 'vpn'], 'app-dev' => ['app-dev'], 'operator' => []];
}

/** @return list<string> */
function commandLog(array $fixture): array
{
    return is_file($fixture['commands']) ? file($fixture['commands'], FILE_IGNORE_NEW_LINES) : [];
}

describe('Gateway convergence', function () {
    it('skips apt when all Gateway prerequisites are installed', function () {
        $fixture = guestScriptFixture('converge-gateway.sh', [
            'dpkg-query' => "printf 'install ok installed'",
            'apt-get' => "printf '%s\\n' \"\$*\" >> '__ROOT__/commands'",
        ]);

        expect(runGuestScript($fixture, ['prerequisites'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([]);
    });

    it('installs only the missing Gateway prerequisites in fixed order', function (string $missing, string $packages) {
        $fixture = guestScriptFixture('converge-gateway.sh', [
            'dpkg-query' => "case \",\$DPKG_MISSING_PACKAGES,\" in *\",\${@: -1},\"*) exit 1 ;; esac\nprintf 'install ok installed'",
            'apt-get' => "printf '%s %s\\n' \"\$DEBIAN_FRONTEND\" \"\$*\" >> '__ROOT__/commands'",
        ]);

        expect(runGuestScript($fixture, ['prerequisites'], ['DPKG_MISSING_PACKAGES' => $missing])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                'noninteractive update',
                'noninteractive install --yes --no-install-recommends -- '.$packages,
            ]);
    })->with([
        'FPM' => ['php8.5-fpm', 'php8.5-fpm'],
        'database drivers' => ['php8.5-mysql,php8.5-pgsql', 'php8.5-mysql php8.5-pgsql'],
        'all' => ['php8.5-pgsql,caddy,php8.5-fpm,dnsmasq,php8.5-mysql', 'caddy dnsmasq php8.5-fpm php8.5-mysql php8.5-pgsql'],
    ]);

    it('points a Gateway environment that names another checkout at the mounted checkout', function () {
        $fixture = guestScriptFixture('converge-gateway.sh', ['dpkg-query' => "printf 'install ok installed'"]);
        $environment = "{$fixture['home']}/orbit/apps/gateway/.env";
        file_put_contents($environment, "APP_ENV=local\nORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit-gateway\nORBIT_HOME=/home/orbit/.orbit\n");

        expect(runGuestScript($fixture, ['prerequisites'])->getExitCode())->toBe(0)
            ->and(file_get_contents($environment))
            ->toBe("APP_ENV=local\nORBIT_HOME=/home/orbit/.orbit\nORBIT_GATEWAY_CHECKOUT={$fixture['home']}/orbit/apps/gateway\n");
    });

    it('runs Gateway Artisan commands from the Gateway checkout', function () {
        $fixture = guestScriptFixture('converge-gateway.sh', [
            'sudo' => GUEST_SUDO_STUB,
            'install' => 'mkdir -p "${@: -1}"',
            'chown' => 'exit 0',
            'php' => "printf '%s %s\\n' \"\$PWD\" \"\$*\" >> '__ROOT__/commands'",
        ]);

        expect(runGuestScript($fixture, ['bootstrap', 'gateway'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                "{$fixture['home']}/orbit/apps/gateway artisan migrate --force",
                "{$fixture['home']}/orbit/apps/gateway artisan orbit:bootstrap gateway --no-interaction",
            ]);
    });

    it('keeps every guest and image script valid Bash', function () {
        $resources = dirname(__DIR__, 3).'/resources';
        $scripts = [...glob($resources.'/guest/*.sh'), ...glob($resources.'/image/*.sh')];

        expect($scripts)->toHaveCount(8);
        foreach ($scripts as $script) {
            expect(new Process(['bash', '-n', $script], timeout: 5)->run())->toBe(0, $script);
        }
    });
});

describe('workload Node convergence', function () {
    it('provisions app-dev when the Gateway store has no active node role', function () {
        $fixture = convergenceAppFixture('converge-app-dev.sh');

        expect(runGuestScript($fixture, ['app-dev', '192.0.2.11', 'x86_64'])->getExitCode())->toBe(0)
            ->and(implode("\n", commandLog($fixture)))
            ->toContain('orbit:node-provision app-dev 192.0.2.11 --role=app-dev --tld=beast --architecture=x86_64 --user=orbit --wireguard-ip=10.44.0.2 --host-key-fingerprint=SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA --no-interaction');
    });

    it('skips provisioning when the Gateway store already holds the active node role', function () {
        $fixture = convergenceAppFixture('converge-app-dev.sh');

        expect(runGuestScript($fixture, ['app-dev', '192.0.2.11', 'x86_64'], ['ORBIT_PHP_NODE_ACTIVE' => '1'])->getExitCode())->toBe(0)
            ->and(implode("\n", commandLog($fixture)))->not->toContain('orbit:node-provision');
    });

    it('fails closed when provisioning fails', function () {
        $fixture = convergenceAppFixture('converge-app-dev.sh');

        expect(runGuestScript($fixture, ['app-dev', '192.0.2.11', 'x86_64'], ['ORBIT_PHP_FAIL' => 'provision'])->getExitCode())->not->toBe(0);
    });

    it('refuses invalid workload arguments before provisioning', function (string $script, array $arguments, int $exit) {
        $fixture = convergenceAppFixture($script);

        expect(runGuestScript($fixture, $arguments)->getExitCode())->toBe($exit)
            ->and(implode("\n", commandLog($fixture)))->not->toContain('orbit:node-provision');
    })->with([
        'app-dev arity' => ['converge-app-dev.sh', ['app-dev', '192.0.2.11'], 64],
        'app-dev address' => ['converge-app-dev.sh', ['app-dev', 'example', 'x86_64'], 64],
        'app-dev architecture' => ['converge-app-dev.sh', ['app-dev', '192.0.2.11', 'armv7'], 65],
        'app-prod WireGuard address' => ['converge-app-prod.sh', ['app-prod', '192.0.2.12', 'x86_64', '10.45.0.3'], 65],
    ]);

    it('provisions app-prod with its own preview TLD and WireGuard address and prepares its runtime', function (string $node, string $address) {
        $fixture = convergenceAppFixture('converge-app-prod.sh');
        $log = static fn (): string => implode("\n", commandLog($fixture));

        expect(runGuestScript($fixture, [$node, '192.0.2.12', 'aarch64', $address])->getExitCode())->toBe(0)
            ->and($log())
            ->toContain("orbit:node-provision {$node} 192.0.2.12 --role=app-prod --tld={$node} --architecture=aarch64 --user=orbit --wireguard-ip={$address} ")
            ->toContain("ssh -i {$fixture['home']}/.orbit/ssh/id_ed25519");
    })->with([
        'primary production' => ['app-prod', '10.44.0.3'],
        'production extension' => ['app-prod-2', '10.44.0.4'],
    ]);

    it('prepares the app-prod runtime again without provisioning an active Node', function () {
        $fixture = convergenceAppFixture('converge-app-prod.sh');

        expect(runGuestScript($fixture, ['app-prod', '192.0.2.12', 'x86_64', '10.44.0.3'], ['ORBIT_PHP_NODE_ACTIVE' => '1'])->getExitCode())->toBe(0)
            ->and(implode("\n", commandLog($fixture)))
            ->not->toContain('orbit:node-provision')
            ->toContain('orbit@10.44.0.3 bash -se');
    });

    it('writes no Caddy file on a Node and leaves the whole Caddyfile to the Node Caddy build', function () {
        foreach (['converge-app-dev.sh', 'converge-app-prod.sh', 'converge-gateway.sh'] as $script) {
            expect(guestScriptSource($script))
                ->not->toContain('/etc/caddy', 'local_certs', 'caddy validate', 'systemctl reload caddy');
        }
        expect(guestScriptSource('converge-app-prod.sh'))->toContain(
            "dpkg-query -W -f='\${Status}' php8.5-fpm",
            'apt-get install --yes --no-install-recommends -- php8.5-fpm',
            'systemctl enable --now php8.5-fpm',
            'install -d -m 0755 /var/www',
        );
    });
});

describe('operator convergence', function () {
    it('refuses invalid arguments for each mode before any work', function (array $arguments) {
        $fixture = operatorFixture();

        expect(runGuestScript($fixture, $arguments)->getExitCode())->toBe(64)
            ->and(commandLog($fixture))->toBe([]);
    })->with([
        'no mode' => [[]],
        'unknown mode' => [['retarget']],
        'provision arity' => [['provision', '192.0.2.14', 'x86_64']],
        'provision address' => [['provision', 'operator', 'x86_64', '10.44.0.5']],
        'provision architecture' => [['provision', '192.0.2.14', 'riscv64', '10.44.0.5']],
        'provision WireGuard address' => [['provision', '192.0.2.14', 'x86_64', '10.44.1.5']],
        'grant arity' => [['grant', 'operator']],
        'grant Node name' => [['grant', 'operator', 'gate way']],
        'configure-cli arity' => [['configure-cli']],
        'configure-cli Gateway host' => [['configure-cli', '-gateway']],
        'role-add arity' => [['role-add', 'app-prod']],
        'role-add Node' => [['role-add', 'gateway', 'ingress']],
        'role-add role' => [['role-add', 'app-prod', 'router']],
    ])->skip(fn (): bool => posix_geteuid() === 0, 'grant, configure-cli, and role-add re-run themselves through sudo as root.');

    it('adds a declared role to a workload Node through the topology Gateway', function () {
        $fixture = operatorFixture();

        expect(runGuestScript($fixture, ['role-add', 'app-prod', 'ingress'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe(['orbit node:role:add app-prod ingress --json']);
    })->skip(fn (): bool => posix_geteuid() === 0, 'role-add re-runs itself through sudo as root.');

    it('skips provisioning when the operator is already active in the Gateway store', function () {
        $fixture = operatorFixture();
        operatorStore($fixture['home'], ['gateway' => 'active', 'operator' => 'active']);

        expect(runGuestScript($fixture, ['provision', '192.0.2.14', 'x86_64', '10.44.0.5'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([]);
    });

    it('provisions a roleless operator with its scanned host key', function () {
        $fixture = operatorFixture();
        operatorStore($fixture['home'], ['gateway' => 'active', 'operator' => 'provisioning']);

        expect(runGuestScript($fixture, ['provision', '192.0.2.14', 'x86_64', '10.44.0.5'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                'ssh-keyscan -T 5 -t ed25519 -- 192.0.2.14',
                'artisan orbit:node-provision operator 192.0.2.14 --architecture=x86_64 --user=orbit --wireguard-ip=10.44.0.5 '
                    .'--host-key-fingerprint=SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB --no-interaction',
            ]);
    });

    it('adds the Gateway, then grants the operator Node access to the Gateway Node by id', function () {
        $fixture = operatorFixture();
        mkdir("{$fixture['home']}/.orbit/ca", 0o700);
        file_put_contents("{$fixture['home']}/.orbit/ca/root.pem", "root CA\n");
        file_put_contents("{$fixture['root']}/nodes.json", json_encode(['nodes' => [
            ['id' => 1, 'name' => 'gateway'],
            ['id' => 3, 'name' => 'app-dev'],
            ['id' => 4, 'name' => 'operator'],
        ]], JSON_THROW_ON_ERROR));

        expect(runGuestScript($fixture, ['grant', 'operator', 'gateway'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                "orbit gateway:add https://10.44.0.1 --name=e2e --ca={$fixture['home']}/.orbit/ca/root.pem --use --json",
                'orbit node:list --json',
                'orbit node:access:add 4 1 --json',
            ]);
    })->skip(fn (): bool => posix_geteuid() === 0, 'grant re-runs itself through sudo as root.');

    it('grants nothing when a named Node is not registered', function () {
        $fixture = operatorFixture();
        mkdir("{$fixture['home']}/.orbit/ca", 0o700);
        file_put_contents("{$fixture['home']}/.orbit/ca/root.pem", "root CA\n");
        file_put_contents("{$fixture['root']}/nodes.json", json_encode(['nodes' => [['id' => 1, 'name' => 'gateway']]], JSON_THROW_ON_ERROR));

        expect(runGuestScript($fixture, ['grant', 'operator', 'gateway'])->getExitCode())->toBe(65)
            ->and(implode("\n", commandLog($fixture)))->not->toContain('node:access:add');
    })->skip(fn (): bool => posix_geteuid() === 0, 'grant re-runs itself through sudo as root.');
});

describe('topology verifier', function () {

    it('refuses arguments outside its contract without evidence', function (array $arguments) {
        $process = runGuestScript(verifierFixture(), $arguments);

        expect($process->getExitCode())->toBe(64)
            ->and($process->getOutput())->toBe('');
    })->with([
        'no arguments' => [[]],
        'no instance' => [['service.gateway', str_repeat('a', 40)]],
        'short source SHA' => [['service.gateway', 'abc', 'orbit-e2e-gateway']],
        'invalid instance' => [['service.gateway', str_repeat('a', 40), 'Orbit_Gateway']],
        'unknown probe' => [['role.gateway', str_repeat('a', 40), 'orbit-e2e-gateway']],
        'Node set missing' => [['nodes.registered', str_repeat('a', 40), 'orbit-e2e-gateway']],
        'Node set not base64' => [['nodes.registered', str_repeat('a', 40), 'orbit-e2e-gateway', '{"gateway":[]}']],
        'no reachable Nodes' => [['wireguard.reachability', str_repeat('a', 40), 'orbit-e2e-gateway']],
        'mount token not a hash' => [['source.app-dev', str_repeat('a', 40), 'orbit-e2e-app-dev', 'token']],
        'source on a production Node' => [['source.app-prod', str_repeat('a', 40), 'orbit-e2e-app-prod', str_repeat('b', 64)]],
    ]);

    it('passes nodes.registered on the exact declared Nodes and prints one JSON evidence line', function () {
        $process = nodesRegistered(verifierFixture(), declaredNodes(), [
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['vpn', 'gateway', 'metrics']],
            ['name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(substr_count($process->getOutput(), "\n"))->toBe(1);
        $evidence = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        expect(array_keys($evidence))->toBe(['probe', 'passed', 'identity', 'checked_at', 'expected', 'observed', 'evidence_ref'])
            ->and($evidence)->toMatchArray([
                'probe' => 'nodes.registered',
                'passed' => true,
                'identity' => str_repeat('a', 40),
                'expected' => 'gateway:gateway+vpn,app-dev:app-dev,operator:none',
                'observed' => 'gateway:gateway+metrics+vpn,app-dev:app-dev,operator:none',
                'evidence_ref' => 'incus://orbit-e2e-tst-9-aaaaaaaa-gateway/nodes.registered',
            ])
            ->and($evidence['checked_at'])->toMatch('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/');
    });

    it('fails nodes.registered unless exactly the declared Nodes hold their roles', function (array $listed, string $reason) {
        $process = nodesRegistered(verifierFixture(), declaredNodes(), $listed);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain($reason);
    })->with([
        'undeclared Node' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway', 'vpn']],
            ['name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev']],
            ['name' => 'app-prod', 'status' => 'active', 'roles' => ['app-prod']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ], 'registered Nodes [app-dev,app-prod,gateway,operator] differ from declared [app-dev,gateway,operator]'],
        'missing Node' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway', 'vpn']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ], 'registered Nodes [gateway,operator] differ from declared [app-dev,gateway,operator]'],
        'missing role' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway']],
            ['name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ], 'Node gateway lacks roles [vpn]'],
        'operator with a role' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway', 'vpn']],
            ['name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev']],
            ['name' => 'operator', 'status' => 'active', 'roles' => ['app-dev']],
        ], 'Node operator must stay roleless'],
        'inactive Node' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway', 'vpn']],
            ['name' => 'app-dev', 'status' => 'provisioning', 'roles' => ['app-dev']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ], 'Node app-dev is not active'],
    ]);

    it('passes source on a mounted worktree only when its mount token hashes to the recorded hash', function (string $change, int $exit) {
        $fixture = verifierFixture();
        $sha = str_repeat('c', 40);
        $token = bin2hex(random_bytes(32))."\n";
        $hash = hash('sha256', $token);
        mkdir("{$fixture['home']}/orbit/.e2e", 0o700);
        file_put_contents("{$fixture['home']}/orbit/apps/gateway/artisan", "<?php\n");
        file_put_contents("{$fixture['home']}/orbit/.e2e/mount-token", $change === 'token rotated' ? "rotated\n" : $token);
        file_put_contents("{$fixture['state']}/source-state", json_encode(['sha' => $sha, 'mounted' => true, 'mount_token_sha256' => $hash], JSON_THROW_ON_ERROR));
        $expected = $change === 'other hash expected' ? str_repeat('d', 64) : $hash;

        $process = runGuestScript($fixture, ['source.app-dev', $sha, 'orbit-e2e-app-dev', $expected], ['MOUNTPOINT_EXIT' => $change === 'not mounted' ? '32' : '0']);

        expect($process->getExitCode())->toBe($exit);
        if ($exit === 0) {
            expect(json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR))->toMatchArray([
                'probe' => 'source.app-dev',
                'expected' => "{$sha}:mount-token={$hash}",
                'observed' => "{$sha}:mount-token={$hash}",
                'evidence_ref' => 'incus://orbit-e2e-app-dev/source.app-dev',
            ]);
        } else {
            expect($process->getOutput())->toBe('');
        }
    })->with([
        'mounted' => ['none', 0],
        'token rotated' => ['token rotated', 1],
        'other hash expected' => ['other hash expected', 1],
        'not mounted' => ['not mounted', 1],
    ]);
});
