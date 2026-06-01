<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

describe('install-orbit Docker-first runtime contract', function (): void {
    beforeEach(function (): void {
        $this->installer = File::get(repo_path('bin/install-orbit'));
    });

    it('does not install or restart host Caddy, PHP-FPM, Composer, or Supervisor', function (): void {
        expect($this->installer)
            ->not->toContain('install_sury_php_repo')
            ->not->toContain('SURY_PHP_REPO_URL')
            ->not->toContain('php-fpm')
            ->not->toContain('-fpm"')
            ->not->toContain('-bcmath')
            ->not->toContain('-zip"')
            ->not->toContain('supervisor')
            ->not->toContain('install -y composer')
            ->not->toContain('apt-get install -y caddy')
            ->not->toContain(' caddy ')
            ->not->toContain('sudo systemctl restart caddy')
            ->not->toContain('sudo systemctl restart php')
            ->not->toContain('sudo systemctl enable caddy');
    });

    it('installs Docker engine prerequisites on Ubuntu before invoking the runtime container', function (): void {
        expect($this->installer)
            ->toContain('install_docker_engine_ubuntu')
            ->toContain('docker-ce')
            ->toContain('docker-ce-cli')
            ->toContain('containerd.io')
            ->toContain('docker-buildx-plugin')
            ->toContain('docker-compose-plugin')
            ->toContain('systemctl enable --now docker');
    });

    it('builds orbit-runtime from the checked-out source and pulls official runtime dependency images', function (): void {
        expect($this->installer)
            ->toContain('docker_cli build')
            ->toContain('docker/orbit-runtime/Dockerfile')
            ->toContain('-t "orbit-runtime:current"')
            ->toContain('docker_cli pull "caddy:2-alpine"')
            ->toContain('docker_cli pull "dunglas/frankenphp:1-php8.5-bookworm"')
            ->toContain('ghcr.io/wg-easy/wg-easy:15')
            ->not->toContain('docker/orbit-caddy/Dockerfile')
            ->not->toContain('-t "orbit-caddy:current"');
    });

    it('can load runtime dependency images from staged archives before falling back to Docker Hub', function (): void {
        expect($this->installer)
            ->toContain('RUNTIME_IMAGE_ARCHIVE="${ORBIT_RUNTIME_IMAGE_ARCHIVE:-}"')
            ->toContain('CADDY_IMAGE_ARCHIVE="${ORBIT_CADDY_IMAGE_ARCHIVE:-}"')
            ->toContain('DNSMASQ_IMAGE_ARCHIVE="${ORBIT_DNSMASQ_IMAGE_ARCHIVE:-}"')
            ->toContain('FRANKENPHP_IMAGE_ARCHIVE="${ORBIT_FRANKENPHP_IMAGE_ARCHIVE:-}"')
            ->toContain('WG_EASY_IMAGE_ARCHIVE="${ORBIT_WG_EASY_IMAGE_ARCHIVE:-}"')
            ->toContain('--runtime-image-archive=PATH')
            ->toContain('--caddy-image-archive=PATH')
            ->toContain('--dnsmasq-image-archive=PATH')
            ->toContain('--frankenphp-image-archive=PATH')
            ->toContain('--wg-easy-image-archive=PATH')
            ->toContain('docker_cli load -i "$RUNTIME_IMAGE_ARCHIVE"')
            ->toContain('docker_cli load -i "$CADDY_IMAGE_ARCHIVE"')
            ->toContain('docker_cli load -i "$DNSMASQ_IMAGE_ARCHIVE"')
            ->toContain('docker_cli load -i "$FRANKENPHP_IMAGE_ARCHIVE"')
            ->toContain('docker_cli load -i "$WG_EASY_IMAGE_ARCHIVE"')
            ->toContain('docker_cli image inspect "orbit-runtime:current"')
            ->toContain('docker_cli image inspect "4km3/dnsmasq:latest"')
            ->toContain('docker_cli image inspect "caddy:2-alpine"')
            ->toContain('docker_cli image inspect "dunglas/frankenphp:1-php8.5-bookworm"')
            ->toContain('docker_cli image inspect "ghcr.io/wg-easy/wg-easy:15"')
            ->toContain('docker_cli pull "caddy:2-alpine"')
            ->toContain('docker_cli pull "dunglas/frankenphp:1-php8.5-bookworm"');
    });

    it('fails early when a supplied wg-easy image archive is missing', function (): void {
        $source = tempnam(sys_get_temp_dir(), 'orbit-install-source-');

        try {
            $process = new Process([
                repo_path('bin/install-orbit'),
                "--source-archive={$source}",
                '--wg-easy-image-archive=/tmp/orbit-wg-easy-does-not-exist.tar',
                '--skip-prerequisites',
            ]);

            $process->run();

            expect($process->isSuccessful())->toBeFalse();
            expect($process->getErrorOutput())->toContain('wg-easy image archive not found');
        } finally {
            @unlink($source);
        }
    });

    it('marks archive-seeded installs so node:new can forward local runtime images during E2E provisioning', function (): void {
        expect($this->installer)
            ->toContain('ORBIT_FORWARD_INSTALL_IMAGE_ARCHIVES')
            ->toContain('write_env_var "ORBIT_FORWARD_INSTALL_IMAGE_ARCHIVES" "1"');
    });

    it('runs gateway composer install plus key generation inside orbit-runtime without a CLI source step', function (): void {
        expect($this->installer)
            ->toContain('docker_cli run --rm')
            ->toContain('orbit-runtime:current')
            ->toContain('--workdir /opt/orbit/apps/gateway')
            ->toContain('grep -q \'^APP_KEY=base64:\' \"\$ORBIT_CONFIG_ROOT/gateway/.env\"')
            ->not->toContain('--workdir /opt/orbit/apps/cli')
            ->toContain('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
            ->toContain('php artisan key:generate --force --no-interaction')
            ->not->toContain('php /opt/orbit/artisan')
            ->not->toContain('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader"')
            ->not->toContain('cd $target && composer install')
            ->not->toContain('cd $target && php artisan');
    });

    it('stores gateway mutable state under the Orbit config root', function (): void {
        expect($this->installer)
            ->toContain('GATEWAY_ENV_FILE="$GATEWAY_STATE_DIR/.env"')
            ->toContain('"$TARGET_DIR/apps/gateway/.env.example"')
            ->toContain('--env "ORBIT_CONFIG_ROOT=$CONFIG_ROOT"')
            ->toContain('--mount "type=bind,source=$CONFIG_ROOT,target=$CONFIG_ROOT"')
            ->toContain('[ ! -f "$TARGET_DIR/apps/gateway/artisan" ]')
            ->not->toContain('$TARGET_DIR/apps/gateway/database/database.sqlite')
            ->not->toContain('"$TARGET_DIR/.env"')
            ->not->toContain('"$TARGET_DIR/.env.example"')
            ->not->toContain('[ ! -f "$TARGET_DIR/artisan" ]');
    });

    it('clears stale Laravel bootstrap cache files before no-dev runtime composer install', function (): void {
        $cacheClear = strpos($this->installer, "\n    clear_laravel_bootstrap_cache\n");
        $composerInstall = strpos($this->installer, 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');

        expect($this->installer)
            ->toContain('clear_laravel_bootstrap_cache()')
            ->toContain('"$TARGET_DIR/apps/gateway/bootstrap/cache"/*.php')
            ->and($cacheClear)->not->toBeFalse()
            ->and($composerInstall)->not->toBeFalse()
            ->and($cacheClear)->toBeLessThan($composerInstall);
    });

    it('creates an operation token secret for the gateway app during bootstrap', function (): void {
        expect($this->installer)
            ->toContain('ensure_operation_token_secret')
            ->toContain('ORBIT_OPERATION_TOKEN_SECRET')
            ->toContain('generate_operation_token_secret')
            ->toContain('openssl rand -base64 32')
            ->toContain('remove_env_var "ORBIT_EXECUTOR_SECRET"')
            ->not->toContain('export ORBIT_EXECUTOR_SECRET')
            ->not->toContain('write_env_var "ORBIT_EXECUTOR_SECRET"');
    });

    it('persists a supplied node identity for the CLI local executor when provisioning a known node', function (): void {
        expect($this->installer)
            ->toContain('ORBIT_NODE_IDENTITY')
            ->toContain('write_env_var "ORBIT_NODE_IDENTITY"');
    });

    it('starts the orbit-runtime container with the documented Docker-first shape', function (): void {
        expect($this->installer)
            ->toContain('docker_cli network create')
            ->toContain('orbit-network')
            ->toContain('docker_cli run -d')
            ->toContain('--pull never')
            ->toContain('--name orbit-runtime')
            ->toContain('--restart unless-stopped')
            ->toContain('--network orbit-network')
            ->toContain('--network-alias orbit-runtime')
            ->toContain('orbit.container.kind=runtime')
            ->toContain('ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1')
            ->toContain('target=/opt/orbit')
            ->toContain('/var/run/docker.sock');
    });

    it('prepares /etc/caddy and /etc/orbit on the host and bind-mounts them into orbit-runtime so gateway bootstrap writes land where orbit-caddy reads', function (): void {
        expect($this->installer)
            ->toContain('prepare_orbit_caddy_host_paths')
            ->toContain('install -d -m 0755')
            ->toContain('/etc/caddy')
            ->toContain('/etc/caddy/orbit')
            ->toContain('/etc/caddy/sites')
            ->toContain('/etc/orbit')
            ->toContain('/etc/orbit/certs')
            ->toContain('--mount "type=bind,source=/etc/caddy,target=/etc/caddy"')
            ->toContain('--mount "type=bind,source=/etc/orbit,target=/etc/orbit"');
    });

    it('grants the runtime user docker group membership and uses sudo for install-time docker invocations so a fresh runtime user does not need the docker socket up front', function (): void {
        expect($this->installer)
            ->toContain('grant_runtime_user_docker_access')
            ->toContain('usermod -aG docker')
            ->toContain('docker_cli()')
            ->toContain('sudo -n docker')
            ->toContain('docker_cli info')
            ->toContain('docker_cli build')
            ->toContain('docker_cli pull')
            ->toContain('docker_cli run')
            ->toContain('docker_cli container inspect')
            ->toContain('docker_cli network');

        // Walk every line of bin/install-orbit looking for a literal
        // `docker ` invocation that is not routed through docker_cli. The
        // helper itself, plus comments/docs, are exempt — but every
        // install-time docker call must go through the helper so the fresh
        // runtime user does not hit a permission-denied socket before the
        // docker group is picked up.
        $offenders = [];
        $insideDockerCliFn = false;
        $insideUsage = false;

        foreach (preg_split('/\R/', $this->installer) ?: [] as $lineNumber => $line) {
            $trimmed = trim($line);

            if (str_contains($line, "<<'HELP'")) {
                $insideUsage = true;
            }

            if ($insideUsage) {
                if ($trimmed === 'HELP') {
                    $insideUsage = false;
                }

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, 'docker_cli()')) {
                $insideDockerCliFn = true;

                continue;
            }

            if ($insideDockerCliFn) {
                if ($trimmed === '}') {
                    $insideDockerCliFn = false;
                }

                continue;
            }

            // Catches `docker ...` or `run docker ...` at the start of a
            // command. Ignores `docker_cli` and lines that mention `docker`
            // only as a label/string value (no leading whitespace or `run`).
            if (preg_match('/^(?:\s*|\s*run\s+)docker(\s+|$)/', $line) === 1) {
                $offenders[] = sprintf('line %d: %s', $lineNumber + 1, $trimmed);
            }
        }

        expect($offenders)->toBe([], 'install-orbit must route every Docker invocation through docker_cli so the fresh runtime user does not hit a permission-denied socket before the docker group is picked up.');
    });

    it('runs Docker-touching install steps in a way that survives an unprivileged runtime user (sudo-wrapped) on a fresh host', function (): void {
        $root = sys_get_temp_dir().'/orbit-install-orbit-docker-access-'.bin2hex(random_bytes(4));
        $bin = "{$root}/bin";
        $callLog = "{$root}/docker-calls.log";
        $logFile = "{$root}/install.log";
        $stateDir = "{$root}/state";
        $targetDir = "{$root}/orbit";

        mkdir($bin, recursive: true);
        mkdir($stateDir, recursive: true);
        mkdir($targetDir, recursive: true);
        file_put_contents("{$stateDir}/exists", '0');
        file_put_contents("{$stateDir}/running", 'false');
        file_put_contents("{$stateDir}/env", '');

        // The fake `docker` rejects every call that is not invoked through
        // sudo. If install-orbit reverts to plain `docker info` / `docker
        // build` while the runtime user lacks docker group membership, this
        // assertion catches it as a permission-denied probe.
        file_put_contents("{$bin}/docker", <<<'BASH'
#!/usr/bin/env bash
{
    printf 'docker'
    for arg in "$@"; do printf ' %s' "$arg"; done
    printf '\n'
} >> "$DOCKER_CALL_LOG"

if [ "$ORBIT_INSTALL_DOCKER_FORCE_SUDO" = "1" ] && [ "${SUDO_USER:-}" = "" ]; then
    printf 'permission denied while trying to connect to the Docker daemon socket\n' >&2
    exit 1
fi

case "$1" in
    info) exit 0 ;;
    network)
        if [ "${2:-}" = "inspect" ]; then
            exit 1
        fi
        exit 0
        ;;
    container)
        if [ "${2:-}" = "inspect" ]; then
            if [ "$(cat "$DOCKER_STATE_DIR/exists")" != "1" ]; then exit 1; fi
            for arg in "$@"; do
                case "$arg" in
                    '{{.State.Running}}') cat "$DOCKER_STATE_DIR/running"; exit 0 ;;
                    '{{range .Config.Env}}{{println .}}{{end}}') cat "$DOCKER_STATE_DIR/env"; printf '\n'; exit 0 ;;
                esac
            done
            printf '{}\n'
            exit 0
        fi
        ;;
    rm) printf '0' > "$DOCKER_STATE_DIR/exists"; exit 0 ;;
    start) printf 'true' > "$DOCKER_STATE_DIR/running"; exit 0 ;;
    run)
        printf '1' > "$DOCKER_STATE_DIR/exists"
        printf 'true' > "$DOCKER_STATE_DIR/running"
        exit 0
        ;;
    image) exit 0 ;;
esac

exit 0
BASH);
        chmod("{$bin}/docker", 0755);

        // sudo stub: records each invocation, sets SUDO_USER, and then
        // execs the wrapped command. This proves install-orbit's docker
        // calls reach `docker` through `sudo -n docker ...`.
        file_put_contents("{$bin}/sudo", <<<'BASH'
#!/usr/bin/env bash
shift_count=0
for arg in "$@"; do
    case "$arg" in
        -*) shift_count=$((shift_count+1)) ;;
        *) break ;;
    esac
done
shift "$shift_count"
SUDO_USER="${SUDO_USER:-test}" exec "$@"
BASH);
        chmod("{$bin}/sudo", 0755);

        file_put_contents("{$bin}/install", "#!/usr/bin/env bash\nexit 0\n");
        chmod("{$bin}/install", 0755);

        $command = sprintf(
            'export PATH=%s:$PATH; export DOCKER_CALL_LOG=%s; export DOCKER_STATE_DIR=%s; export ORBIT_INSTALL_LOG=%s; export ORBIT_INSTALL_PATH=%s; export ORBIT_INSTALL_DOCKER_FORCE_SUDO=1; source %s; GATEWAY_MODE=1; require_docker; build_runtime_images; start_runtime_container; echo "OK"',
            escapeshellarg($bin),
            escapeshellarg($callLog),
            escapeshellarg($stateDir),
            escapeshellarg($logFile),
            escapeshellarg($targetDir),
            escapeshellarg(repo_path('bin/install-orbit')),
        );

        $process = new Process(['bash', '-c', $command]);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $calls = is_file($callLog)
            ? array_values(array_filter(array_map('trim', explode("\n", file_get_contents($callLog) ?: ''))))
            : [];

        try {
            expect($process->getExitCode())
                ->toBe(0, "install-orbit must succeed when the runtime user lacks the docker group; stderr was:\n{$stderr}\nstdout was:\n{$stdout}");

            expect($calls)->not->toBeEmpty();

            $dockerRunCalls = array_filter($calls, fn (string $line): bool => str_starts_with($line, 'docker run -d') && str_contains($line, '--name orbit-runtime'));

            expect($dockerRunCalls)->not->toBeEmpty('expected install-orbit to start orbit-runtime through sudo-wrapped docker run');
        } finally {
            File::deleteDirectory($root);
        }
    });

    it('runs migrations in a disposable orbit-runtime container before starting the long-running runtime', function (): void {
        $migrationStep = strpos($this->installer, 'start_step "Run migrations inside orbit-runtime"');
        $startRuntimeStep = strpos($this->installer, 'start_step "Start orbit-runtime container"');

        expect($this->installer)
            ->toContain('docker_cli run --rm')
            ->toContain('-v "$TARGET_DIR":/opt/orbit')
            ->toContain('orbit-runtime:current')
            ->toContain('php /opt/orbit/apps/gateway/artisan migrate --force --no-interaction --path=/opt/orbit/apps/gateway/database/migrations --realpath')
            ->not->toContain('php /opt/orbit/apps/gateway/artisan migrate --force --no-interaction"')
            ->not->toContain('php /opt/orbit/artisan migrate --force --no-interaction')
            ->not->toContain('docker_cli exec \\')
            ->and($migrationStep)->not->toBeFalse()
            ->and($startRuntimeStep)->not->toBeFalse()
            ->and($migrationStep)->toBeLessThan($startRuntimeStep);
    });

    it('links the downloaded Orbit CLI binary as the host orbit command', function (): void {
        expect($this->installer)
            ->toContain('ln -sf "$TARGET_DIR/bin/orbit-binary" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/artisan" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/apps/cli/orbit" "$LINK_PATH"');
    });

    it('fails early if Docker is not reachable so the runtime path cannot silently fall back to host PHP', function (): void {
        expect($this->installer)
            ->toContain('require_docker')
            ->toContain('Docker daemon is not reachable');
    });
});
