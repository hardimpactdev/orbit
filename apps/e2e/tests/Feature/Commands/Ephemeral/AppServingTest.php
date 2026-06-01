<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use App\E2E\Support\OrbitCliBinaryBundle;
use Illuminate\Support\Facades\Process;

pest()->group('e2e-provision', 'e2e-provision-serving');

/**
 * Serving regression test for the host PHP toolchain + FrankenPHP/Caddy lane.
 *
 * This test builds a REAL app-dev node through the canonical node:new
 * provisioning path (operator → orbit node:new --roles=app-dev →
 * GatewayNodeCreator → OrbitHostInstaller → bin/install-orbit → role
 * converge), then starts the resulting templates and asserts:
 *
 *   1. `orbit doctor --node=app-dev-1 --family=tool --restore` converges the
 *      host toolchain (php-cli, composer, laravel-installer) and orbit-caddy,
 *      leaving the node healthy.
 *   2. `orbit app:new` creates an app on the dev node.
 *   3. A minimal public/index.php seeded on the dev node is served over HTTP
 *      by the orbit-caddy container, returning HTTP 200.
 *   4. `orbit app:exec <name> -- composer install` succeeds (host-side
 *      PHP execution through the provisioned toolchain).
 *   5. The host toolchain binaries (php8.5, composer, laravel) are present
 *      at the expected paths on the dev node.
 *
 * This lane is deliberately separate from the prepared-full warm pool so it
 * does NOT slow the shared baked topology build. Run via:
 *
 *   composer test:e2e:provision -- --group=e2e-provision-serving
 */
it('provisions a real app-dev node and serves an app end-to-end', function (): void {
    if (getenv('ORBIT_E2E') !== '1') {
        $this->markTestSkipped('Set ORBIT_E2E=1 to run the serving provision test.');
    }

    $config = E2EConfig::fromEnvironment();

    if (! in_array('incus', $config->providerNames, true)) {
        $this->markTestSkipped('Incus provider not configured (ORBIT_E2E_PROVIDER).');
    }

    $hostPool = IncusHostPool::fromEnvironment($config);
    $host = $hostPool->first();

    if ($host === null) {
        $this->markTestSkipped('No Incus host configured (ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST).');
    }

    if (! $host->imageExists($config->baseImage)) {
        $this->markTestSkipped("Required base image [{$config->baseImage}] missing on Incus host.");
    }

    // Use an isolated artifact namespace so this test's templates do not
    // collide with the shared prepared-topology pool.
    $previousArtifactNamespace = getenv(E2ETopologyArtifactNamespace::EnvironmentVariable);
    $artifactNamespace = 'provision-serving-'.getmypid().'-'.bin2hex(random_bytes(4));
    putenv(E2ETopologyArtifactNamespace::EnvironmentVariable.'='.$artifactNamespace);

    $kind = E2ETopologyKind::OperatorGatewayAppdev;
    $roles = IncusTopologyTemplate::rolesFor($kind);
    $templateNames = array_map(
        static fn (string $role): string => IncusTopologyTemplate::templateName($kind, $role),
        $roles,
    );
    // Pre-cleanup in case a prior run left templates behind.
    foreach ($templateNames as $templateName) {
        if ($host->instanceExists($templateName)) {
            $host->run(sprintf('incus delete --force %s', escapeshellarg($templateName)));
        }
    }

    $bundleDir = sys_get_temp_dir().'/orbit-e2e-bundle-serving-'.bin2hex(random_bytes(4));
    mkdir($bundleDir, 0755, true);

    $archive = "{$bundleDir}/orbit-source.tar.gz";
    Process::timeout(300)->run(sprintf(
        'COPYFILE_DISABLE=1 tar --exclude=./.git --exclude=./vendor --exclude=./apps/gateway/vendor --exclude=./apps/gateway/database/*.sqlite --exclude=./apps/gateway/database/*.sqlite-* --exclude=./apps/gateway/bootstrap/cache/*.php --exclude=./node_modules -czf %s -C %s .',
        escapeshellarg($archive),
        escapeshellarg(repo_path()),
    ))->throw();

    foreach (['install-orbit', 'e2e-provision-node', '_e2e-deps.sh'] as $script) {
        copy(repo_path('bin/'.$script), "{$bundleDir}/{$script}");
        chmod("{$bundleDir}/{$script}", 0755);
    }

    $home = (string) (getenv('HOME') ?: '');
    $composerCache = $home !== '' ? "{$home}/.cache/orbit-e2e/composer" : null;

    if ($composerCache !== null && is_dir($composerCache)) {
        mkdir("{$bundleDir}/composer-cache", 0755, true);
        Process::timeout(120)->run(sprintf(
            'cp -R %s %s',
            escapeshellarg(rtrim($composerCache, '/').'/.'),
            escapeshellarg("{$bundleDir}/composer-cache"),
        ))->throw();
    }

    // Bundle the orbit binary so the VM does not need gh/GH_TOKEN during
    // the CLI binary download step in bin/install-orbit.
    (new OrbitCliBinaryBundle)->buildLinuxBinaryInto($bundleDir);

    $remoteBundle = $host->pushBundle($bundleDir);
    $passed = false;

    // The app name is used as the Caddy hostname: <name>.test
    $appName = 'e2e-serve-'.strtolower(bin2hex(random_bytes(3)));
    $appPath = "/home/orbit/apps/{$appName}";
    $devWireGuardIp = '10.6.0.4';
    $operatorUser = $config->operatorUser;

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle($remoteBundle);

        // buildDevelopmentAppStage provisions operator+gateway then runs
        // `orbit node:new --roles=app-dev` on the operator. The real
        // OrbitHostInstaller path runs inside the Incus dev VM: Docker, the
        // orbit runtime, FrankenPHP, and the host toolchain intent rows are
        // all set up. After build() the templates are stopped and snapshotted.
        $manifest = $builder->build($kind);

        expect(array_column($manifest, 'role'))->toBe($roles);

        // ── Start the templates directly for serving assertions ──
        //
        // After build() the templates are stopped with a clean snapshot. Start
        // each one, wait for the Incus agent, then retarget WireGuard so the
        // tunnel comes back up with the new provider IP of the gateway.
        $instances = [];

        foreach (['operator', 'gateway', 'dev'] as $role) {
            $templateName = IncusTopologyTemplate::templateName($kind, $role);

            $startResult = $host->startInstance($templateName);
            expect($startResult->successful())->toBeTrue(
                "Could not start template {$templateName}: {$startResult->errorOutput()}"
            );

            $instance = new IncusInstance($host, $templateName);
            $instance->waitForAgent();
            $instances[$role] = $instance;
        }

        // Retarget WireGuard: update the Endpoint in each peer's wg-orbit.conf
        // to the new provider IP of the gateway, then bring the tunnel back up.
        // The WireGuard private/public keys from build time survive in the
        // template and in the wg-easy database, so only the Endpoint needs
        // patching.
        $gatewayProviderIp = $instances['gateway']->waitForIpv4();

        $retargetScript = sprintf(
            <<<'SH'
set -euo pipefail
WG_CONF=/etc/wireguard/wg-orbit.conf
if [ -f "$WG_CONF" ]; then
    sudo sed -i "s|^Endpoint = .*|Endpoint = %s:51820|" "$WG_CONF"
    sudo wg-quick down wg-orbit >/dev/null 2>&1 || true
    sudo wg-quick up wg-orbit
fi
SH,
            $gatewayProviderIp,
        );

        foreach (['operator', 'gateway', 'dev'] as $role) {
            E2ECommand::exec(
                $instances[$role],
                $retargetScript,
                "Could not retarget WireGuard endpoint on {$role}",
                timeoutSeconds: 60,
            );
        }

        // Ensure wg-easy is running on the gateway and route the 10.6.0.0/24
        // subnet through the wg0 interface. Docker's `unless-stopped` policy
        // should auto-restart the wg-easy container on system boot, but if it
        // is not yet running we start it explicitly.
        E2ECommand::exec(
            $instances['gateway'],
            <<<'SH'
set -euo pipefail
sudo systemctl enable --now docker
if ! docker inspect wg-easy >/dev/null 2>&1; then
    echo "wg-easy container not found on gateway; cannot continue." >&2
    exit 1
fi
if ! docker container inspect --format '{{.State.Running}}' wg-easy | grep -q '^true$'; then
    docker start wg-easy
fi
for i in $(seq 1 30); do
    docker exec wg-easy ip link show wg0 >/dev/null 2>&1 && break
    sleep 1
done
docker exec wg-easy ip addr replace 10.6.0.1/24 dev wg0
docker exec wg-easy ip route replace 10.6.0.0/24 dev wg0
SH,
            'Could not ensure wg-easy is running on gateway',
            timeoutSeconds: 120,
        );

        // Wait for WireGuard peer routes on the gateway → dev path.
        E2ECommand::exec(
            $instances['gateway'],
            sprintf(
                'deadline=$((SECONDS+60)); until ping -c1 -W2 %s >/dev/null 2>&1; do if [ "$SECONDS" -ge "$deadline" ]; then exit 1; fi; sleep 2; done',
                escapeshellarg($devWireGuardIp),
            ),
            'WireGuard tunnel to dev node did not come up',
            timeoutSeconds: 75,
        );

        // Wait for the gateway's supervisor-managed orbit HTTP API to be
        // accepting connections on the WireGuard address. The gateway template
        // was built with supervisor enabled so the API comes up automatically
        // on restart; we wait for the HTTP status endpoint to respond.
        E2ECommand::exec(
            $instances['gateway'],
            'deadline=$((SECONDS+90)); until curl -fsS --max-time 5 http://10.6.0.2/api/status >/dev/null 2>&1; do if [ "$SECONDS" -ge "$deadline" ]; then sudo supervisorctl status >&2 || true; exit 1; fi; sleep 3; done',
            'Gateway HTTP API did not become ready on 10.6.0.2',
            timeoutSeconds: 120,
        );

        // ── Step 1: Converge host toolchain + orbit-caddy via doctor --restore ──
        //
        // node:new (which ran during buildDevelopmentAppStage) already wrote
        // NodeTool intent rows (php-cli, composer, laravel-installer, caddy)
        // with expected_state='installed' to the gateway database.
        // `orbit doctor --restore` drives ToolsFixer to execute each tool's
        // installScript on the dev node via SSH from the gateway.
        $doctorResult = E2ECommand::exec(
            $instances['operator'],
            sprintf(
                'sudo -u %s bash -lc %s',
                escapeshellarg($operatorUser),
                escapeshellarg(sprintf(
                    'cd /home/%s/orbit && orbit doctor --node=app-dev-1 --family=tool --restore --json',
                    $operatorUser,
                )),
            ),
            'orbit doctor --restore failed on app-dev-1',
            timeoutSeconds: 900,
        );

        $doctorPayload = json_decode(trim($doctorResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $doctorData = e2eJsonCommandData($doctorPayload);

        expect($doctorData['doctor']['healthy'])->toBeTrue(
            'doctor --restore left the node unhealthy: '.json_encode($doctorData, JSON_PRETTY_PRINT)
        );

        // ── Step 2: Create a minimal app with orbit app:new ──
        $appNewResult = E2ECommand::exec(
            $instances['operator'],
            sprintf(
                'sudo -u %s bash -lc %s',
                escapeshellarg($operatorUser),
                escapeshellarg(sprintf(
                    'cd /home/%s/orbit && orbit app:new %s --node=app-dev-1 --json',
                    $operatorUser,
                    escapeshellarg($appName),
                )),
            ),
            'orbit app:new failed',
            timeoutSeconds: 180,
        );

        $appNewPayload = json_decode(trim($appNewResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $appNewData = e2eJsonCommandData($appNewPayload);

        expect($appNewData['app']['name'])->toBe($appName)
            ->and($appNewData['app']['node'])->toBe('app-dev-1');

        // ── Step 3: Seed public/index.php and composer.json on the dev node ──
        //
        // app:new creates the source directory at /home/orbit/apps/<name>.
        $indexPhp = "<?php\nhttp_response_code(200);\necho 'orbit-e2e-serving-ok';\n";
        $composerJson = json_encode([
            'name' => "orbit-e2e/{$appName}",
            'require' => [],
            'config' => ['optimize-autoloader' => false],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $seedResult = $instances['dev']->exec(
            sprintf(
                'sudo -u orbit bash -lc %s',
                escapeshellarg(implode(' && ', [
                    sprintf('mkdir -p %s', escapeshellarg("{$appPath}/public")),
                    sprintf('printf %%s %s > %s', escapeshellarg($indexPhp), escapeshellarg("{$appPath}/public/index.php")),
                    sprintf('printf %%s %s > %s', escapeshellarg($composerJson), escapeshellarg("{$appPath}/composer.json")),
                ])),
            ),
            timeoutSeconds: 30,
        );

        expect($seedResult->successful())->toBeTrue(
            "Could not seed app files: {$seedResult->output()}{$seedResult->errorOutput()}"
        );

        // ── Step 4: Run composer install via app:exec (host-side toolchain) ──
        //
        // app:exec shells into the app directory on the dev node and runs the
        // command using the version-matched PHP from /opt/orbit/php/<ver>/bin.
        $execResult = E2ECommand::exec(
            $instances['operator'],
            sprintf(
                'sudo -u %s bash -lc %s',
                escapeshellarg($operatorUser),
                escapeshellarg(sprintf(
                    'cd /home/%s/orbit && orbit app:exec %s -- composer install --no-interaction --no-progress --json',
                    $operatorUser,
                    escapeshellarg($appName),
                )),
            ),
            'orbit app:exec composer install failed',
            timeoutSeconds: 300,
        );

        $execPayload = json_decode(trim($execResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $execData = e2eJsonCommandData($execPayload);

        expect($execData['exit_code'] ?? 0)->toBe(0,
            'composer install inside app:exec failed: '.json_encode($execData, JSON_PRETTY_PRINT)
        );

        // ── Step 5: Curl the served app via WireGuard from inside the gateway ──
        //
        // After app:new the gateway wrote a Caddy site config to the dev node
        // (/etc/caddy/sites/<name>.test.caddy) and reloaded orbit-caddy. The
        // orbit-caddy container on the private dev node binds HTTP port 80 to
        // the WireGuard address (10.6.0.4). The gateway is already on the
        // WireGuard mesh, so curl from the gateway is the simplest path.
        $curlResult = $instances['gateway']->exec(
            sprintf(
                'curl -fsSL --connect-timeout 10 --max-time 30 --resolve %s:80:%s http://%s/',
                escapeshellarg("{$appName}.test"),
                escapeshellarg($devWireGuardIp),
                escapeshellarg("{$appName}.test"),
            ),
            timeoutSeconds: 60,
        );

        expect($curlResult->successful())->toBeTrue(
            "curl of served app failed: {$curlResult->output()}{$curlResult->errorOutput()}"
        )
            ->and($curlResult->output())->toContain('orbit-e2e-serving-ok');

        // ── Step 6: Assert the host toolchain binaries are present on dev ──
        $phpVersionResult = $instances['dev']->exec(
            '/opt/orbit/php/8.5/bin/php -r "echo PHP_MAJOR_VERSION.\'.\'.PHP_MINOR_VERSION;"',
            timeoutSeconds: 30,
        );

        expect($phpVersionResult->successful())->toBeTrue(
            "php8.5 not present at /opt/orbit/php/8.5/bin/php: {$phpVersionResult->errorOutput()}"
        )
            ->and(trim($phpVersionResult->output()))->toBe('8.5');

        $composerVersionResult = $instances['dev']->exec(
            '/usr/local/bin/composer --version --no-interaction 2>&1',
            timeoutSeconds: 30,
        );

        expect($composerVersionResult->successful())->toBeTrue(
            "composer not present at /usr/local/bin/composer: {$composerVersionResult->errorOutput()}"
        )
            ->and($composerVersionResult->output())->toContain('Composer');

        $laravelVersionResult = $instances['dev']->exec(
            '/usr/local/bin/laravel --version 2>&1',
            timeoutSeconds: 30,
        );

        expect($laravelVersionResult->successful())->toBeTrue(
            "laravel installer not present at /usr/local/bin/laravel: {$laravelVersionResult->errorOutput()}"
        )
            ->and($laravelVersionResult->output())->toContain('Laravel');

        $passed = true;
    } finally {
        // Stop the running template instances (they were started for assertions).
        foreach ($instances ?? [] as $role => $instance) {
            if ($host->instanceExists($instance->name())) {
                $host->stopInstance($instance->name());
            }
        }

        // Clean up template instances (keep on failure when ORBIT_E2E_KEEP_ON_FAILURE is set).
        $dangling = [];

        foreach ($templateNames ?? [] as $templateName) {
            if ($host->instanceExists($templateName) && ($passed || ! e2eProvisionKeepsFailures())) {
                $host->run(sprintf('incus delete --force %s', escapeshellarg($templateName)));
            } elseif (! $passed && $host->instanceExists($templateName)) {
                $dangling[] = $templateName;
            }
        }

        if ($dangling !== []) {
            e2eProvisionReportDangling($dangling);
        }

        $host->cleanupBundle($remoteBundle ?? '');
        Process::run('rm -rf '.escapeshellarg($bundleDir));

        if (is_string($previousArtifactNamespace)) {
            putenv(E2ETopologyArtifactNamespace::EnvironmentVariable.'='.$previousArtifactNamespace);
        } else {
            putenv(E2ETopologyArtifactNamespace::EnvironmentVariable);
        }
    }
});
