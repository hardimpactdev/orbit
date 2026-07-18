<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

describe('install-orbit gateway bootstrap', function (): void {
    beforeEach(function (): void {
        $this->installer = File::get(repo_path('bin/install-orbit'));
    });

    it('does not keep the retired install-time long-running gateway container helper', function (): void {
        expect($this->installer)
            ->not->toContain('start'.'_runtime_container')
            ->not->toContain('start_gateway_container')
            ->not->toContain('docker_cli run -d')
            ->not->toContain('--restart unless-stopped')
            ->not->toContain('ORBIT_TRUST_WIREGUARD_PROXY_HEADER');
    });

    it('bootstraps gateway state and migrations through disposable orbit-gateway containers', function (): void {
        $bootstrapIndex = strpos($this->installer, 'bootstrap_gateway_state()');
        $migrationIndex = strpos($this->installer, 'run_migrations_in_gateway_image()');

        expect($bootstrapIndex)
            ->not->toBeFalse()->and($migrationIndex)
            ->not->toBeFalse()->and($bootstrapIndex)->toBeLessThan($migrationIndex)->and($this->installer)->toContain(
                'docker_cli run --rm',
            )->and($this->installer)->toContain('--pull never')->and($this->installer)->toContain(
                '"$GATEWAY_IMAGE"',
            )->and($this->installer)->toContain('artisan --version')->and($this->installer)->toContain(
                'migrate --no-interaction --path=/srv/orbit/apps/gateway/database/migrations --realpath',
            );
    });

    it('mounts only the config root into disposable gateway image commands', function (): void {
        expect($this->installer)
            ->toContain('--env "ORBIT_CONFIG_ROOT=$CONFIG_ROOT"')
            ->toContain('--mount "type=bind,source=$CONFIG_ROOT,target=$CONFIG_ROOT"')
            ->not->toContain('-v "$TARGET_DIR":/opt/orbit')
            ->not->toContain('target=/opt/orbit')
            ->not->toContain('php /opt/orbit/artisan migrate --force --no-interaction');
    });

    it('keeps gateway credential state owner-only throughout host bootstrap', function (string $state): void {
        $root = sys_get_temp_dir().'/orbit-install-gateway-modes-'.bin2hex(random_bytes(4));
        $target = "{$root}/orbit";
        $configRoot = "{$root}/config";

        File::ensureDirectoryExists("{$target}/apps/gateway/bootstrap/cache");
        File::put("{$target}/apps/gateway/.env.example", "APP_NAME=Orbit\nAPP_KEY=\n");

        if ($state === 'existing') {
            File::ensureDirectoryExists("{$configRoot}/certs", 0o777);
            chmod($configRoot, 0o777);
            chmod("{$configRoot}/certs", 0o777);
            File::put("{$configRoot}/.env", "APP_NAME=Orbit\nAPP_KEY=base64:existing\n");
            File::put("{$configRoot}/gateway.sqlite", 'existing');
            chmod("{$configRoot}/.env", 0o644);
            chmod("{$configRoot}/gateway.sqlite", 0o644);
        }

        $command = sprintf(
            <<<'BASH'
                export ORBIT_INSTALL_PATH=%s
                export ORBIT_CONFIG_ROOT=%s
                export ORBIT_INSTALL_LOG=%s
                source %s
                require_docker() { :; }
                docker_cli() { :; }
                ensure_forward_install_image_archives_flag() { :; }
                bootstrap_gateway_state
                mode() {
                    stat -c '%%a' "$1" 2>/dev/null || stat -f '%%Lp' "$1"
                }
                printf 'root=%%s\ncerts=%%s\nenv=%%s\ndb=%%s\n' \
                    "$(mode "$CONFIG_ROOT")" \
                    "$(mode "$CONFIG_ROOT/certs")" \
                    "$(mode "$GATEWAY_ENV_FILE")" \
                    "$(mode "$GATEWAY_DATABASE_FILE")"
                grep '^APP_KEY=base64:' "$GATEWAY_ENV_FILE"
                BASH,
            escapeshellarg($target),
            escapeshellarg($configRoot),
            escapeshellarg("{$root}/install.log"),
            escapeshellarg(repo_path('bin/install-orbit')),
        );

        $process = Process::fromShellCommandline($command);
        $process->run();

        try {
            expect($process->getExitCode())
                ->toBe(0, $process->getErrorOutput())
                ->and($process->getOutput())
                ->toContain("root=700\n")
                ->toContain("certs=700\n")
                ->toContain("env=600\n")
                ->toContain("db=600\n")
                ->toContain('APP_KEY=base64:');
        } finally {
            File::deleteDirectory($root);
        }
    })->with(['fresh', 'existing']);
});
