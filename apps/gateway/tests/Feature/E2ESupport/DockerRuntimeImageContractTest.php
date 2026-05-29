<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use Symfony\Component\Process\Process;

pest()->group('e2e', 'e2e-docker-image-contract');

it('defines the orbit runtime image dependency and command contract', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/orbit-runtime/Dockerfile'));

    expect($dockerfile)
        ->toContain('FROM php:8.5-cli-bookworm')
        ->toContain('COPY --from=composer:2 /usr/bin/composer /usr/bin/composer')
        ->toContain('COPY --from=docker:cli /usr/local/bin/docker /usr/local/bin/docker')
        ->toContain('docker-php-ext-install')
        ->toContain('bcmath')
        ->toContain('curl')
        ->toContain('intl')
        ->toContain('mbstring')
        ->toContain('pcntl')
        ->toContain('pdo_sqlite')
        ->toContain('zip')
        ->toContain('git')
        ->toContain('openssh-client')
        ->toContain('procps')
        ->toContain('libsqlite3-dev')
        ->toContain('COPY docker/orbit-runtime/entrypoint.sh /usr/local/bin/orbit-runtime-entrypoint')
        ->toContain('ln -s /usr/local/bin/orbit-runtime-entrypoint /usr/local/bin/orbit')
        ->toContain('WORKDIR /opt/orbit')
        ->toContain('VOLUME ["/opt/orbit"]')
        ->toContain('ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/orbit-runtime-entrypoint"]')
        ->not->toMatch('/^\s+sqlite3\s+\\\\$/m')
        ->not->toContain('COPY . /opt/orbit')
        ->not->toContain('orbit-scheduler')
        ->not->toContain('[program:orbit_scheduler]');
});

it('includes the Docker Compose CLI plugin for Compose-backed Orbit services', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/orbit-runtime/Dockerfile'));

    expect($dockerfile)
        ->toContain('COPY --from=docker:cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose');
});

it('keeps the orbit runtime image build context narrowed to the entrypoint', function (): void {
    $dockerignore = file_get_contents(repo_path('docker/orbit-runtime/Dockerfile.dockerignore'));

    expect($dockerignore)
        ->toContain('**')
        ->toContain('!docker/orbit-runtime/entrypoint.sh')
        ->toContain('apps/gateway/storage/app/orbit/ca/**')
        ->toContain('apps/gateway/storage/app/orbit/certs/**')
        ->toContain('apps/gateway/storage/app/orbit/keys/**')
        ->toContain('.env*')
        ->toContain('vendor/**')
        ->toContain('node_modules/**');
});

it('runs mounted Orbit commands through the entrypoint orbit shim', function (): void {
    $root = sys_get_temp_dir().'/orbit-runtime-entrypoint-'.bin2hex(random_bytes(6));
    $source = "{$root}/source";
    $bin = "{$root}/bin";
    $capture = "{$root}/capture";

    mkdir("{$source}/apps/gateway", recursive: true);
    mkdir($bin, recursive: true);

    file_put_contents("{$source}/apps/gateway/artisan", "<?php\n");
    file_put_contents("{$bin}/php", <<<'BASH'
#!/usr/bin/env bash
printf 'argv=%s\n' "$*" > "$PHP_CAPTURE"
printf 'host_cwd=%s\n' "${ORBIT_HOST_CWD:-}" >> "$PHP_CAPTURE"
BASH);
    chmod("{$bin}/php", 0755);

    $entrypoint = repo_path('docker/orbit-runtime/entrypoint.sh');
    $orbit = "{$root}/orbit";
    symlink($entrypoint, $orbit);

    try {
        $process = new Process(
            ['bash', $orbit, 'about', '--ansi'],
            null,
            [
                'ORBIT_SOURCE_PATH' => $source,
                'ORBIT_HOST_CWD' => '/srv/apps/example',
                'PATH' => $bin.':'.getenv('PATH'),
                'PHP_CAPTURE' => $capture,
            ],
        );

        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and(file_get_contents($capture))
            ->toContain("argv={$source}/apps/gateway/artisan about --ansi")
            ->toContain('host_cwd=/srv/apps/example');
    } finally {
        (new Process(['rm', '-rf', $root]))->run();
    }
});

it('fails clearly when the orbit source mount is missing', function (): void {
    $process = new Process(
        ['bash', repo_path('docker/orbit-runtime/entrypoint.sh'), 'orbit', 'about'],
        null,
        ['ORBIT_SOURCE_PATH' => '/missing/orbit/source'],
    );

    $process->run();

    expect($process->getExitCode())
        ->toBe(1)
        ->and($process->getErrorOutput())
        ->toContain('Orbit source is not mounted at /missing/orbit/source');
});

it('does not fall back to the root artisan shim when the gateway artisan is unavailable', function (): void {
    $root = sys_get_temp_dir().'/orbit-runtime-entrypoint-root-'.bin2hex(random_bytes(6));
    $source = "{$root}/source";
    $bin = "{$root}/bin";
    $capture = "{$root}/capture";

    mkdir($source, recursive: true);
    mkdir($bin, recursive: true);

    file_put_contents("{$source}/artisan", "<?php\n");
    file_put_contents("{$bin}/php", <<<'BASH'
#!/usr/bin/env bash
printf 'argv=%s\n' "$*" > "$PHP_CAPTURE"
BASH);
    chmod("{$bin}/php", 0755);

    try {
        $process = new Process(
            ['bash', repo_path('docker/orbit-runtime/entrypoint.sh'), 'orbit', 'about'],
            null,
            [
                'ORBIT_SOURCE_PATH' => $source,
                'PATH' => $bin.':'.getenv('PATH'),
                'PHP_CAPTURE' => $capture,
            ],
        );

        $process->run();

        expect($process->getExitCode())
            ->toBe(1, $process->getOutput().$process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain("Orbit source is not mounted at {$source}")
            ->and(file_exists($capture))
            ->toBeFalse();
    } finally {
        (new Process(['rm', '-rf', $root]))->run();
    }
});

it('loads gateway app classes ahead of stale root Composer autoload mappings', function (): void {
    $root = sys_get_temp_dir().'/orbit-gateway-artisan-autoload-'.bin2hex(random_bytes(6));
    $gateway = "{$root}/apps/gateway";

    mkdir("{$gateway}/app/Console", recursive: true);
    mkdir("{$gateway}/bootstrap", recursive: true);
    mkdir("{$gateway}/symfony/Input", recursive: true);
    mkdir("{$gateway}/vendor", recursive: true);
    mkdir("{$root}/stale/app/Console", recursive: true);
    mkdir("{$root}/vendor", recursive: true);

    file_put_contents("{$gateway}/artisan", file_get_contents(repo_path('apps/gateway/artisan')));
    chmod("{$gateway}/artisan", 0755);

    file_put_contents("{$gateway}/bootstrap/app.php", <<<'PHP'
<?php

return new App\Console\Kernel;
PHP);

    file_put_contents("{$gateway}/app/Console/Kernel.php", <<<'PHP'
<?php

namespace App\Console;

class Kernel
{
    public function handleCommand(object $input): int
    {
        echo 'gateway';

        return 0;
    }
}
PHP);

    file_put_contents("{$root}/stale/app/Console/Kernel.php", <<<'PHP'
<?php

namespace App\Console;

class Kernel
{
    public function handleCommand(object $input): int
    {
        echo 'stale';

        return 0;
    }
}
PHP);

    file_put_contents("{$gateway}/symfony/Input/ArgvInput.php", <<<'PHP'
<?php

namespace Symfony\Component\Console\Input;

class ArgvInput {}
PHP);

    file_put_contents("{$gateway}/vendor/autoload.php", <<<'PHP'
<?php

spl_autoload_register(static function (string $class): void {
    if ($class === 'Symfony\\Component\\Console\\Input\\ArgvInput') {
        require __DIR__.'/../symfony/Input/ArgvInput.php';

        return;
    }

    if ($class === 'App\\Console\\Kernel') {
        require __DIR__.'/../app/Console/Kernel.php';
    }
}, prepend: true);
PHP);

    file_put_contents("{$root}/vendor/autoload.php", <<<'PHP'
<?php

spl_autoload_register(static function (string $class): void {
    if ($class === 'Symfony\\Component\\Console\\Input\\ArgvInput') {
        require __DIR__.'/../apps/gateway/symfony/Input/ArgvInput.php';

        return;
    }

    if ($class === 'App\\Console\\Kernel') {
        require __DIR__.'/../stale/app/Console/Kernel.php';
    }
}, prepend: true);
PHP);

    try {
        $process = new Process(['php', "{$gateway}/artisan"]);
        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toBe('gateway');
    } finally {
        (new Process(['rm', '-rf', $root]))->run();
    }
});

it('passes non-orbit entrypoint commands through unchanged', function (): void {
    $process = new Process([
        'bash',
        repo_path('docker/orbit-runtime/entrypoint.sh'),
        'printf',
        'ok',
    ]);

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())
        ->toBe('ok');
});

it('does not ship persisted orbit certificate material in the runtime image', function (): void {
    $image = DockerTopologyBuilder::runtimeImage();

    $availability = new Process([
        'docker',
        'image',
        'inspect',
        $image,
    ]);

    $availability->run();

    if ($availability->getExitCode() !== 0) {
        test()->markTestSkipped("Docker runtime image {$image} is not available.");
    }

    $forbiddenPaths = [
        '/opt/orbit-source/apps/gateway/storage/app/orbit/ca',
        '/opt/orbit-source/apps/gateway/storage/app/orbit/certs',
        '/opt/orbit-source/apps/gateway/storage/app/orbit/keys',
        '/home/operator/orbit/apps/gateway/storage/app/orbit/ca',
        '/home/operator/orbit/apps/gateway/storage/app/orbit/certs',
        '/home/operator/orbit/apps/gateway/storage/app/orbit/keys',
        '/home/orbit/orbit/apps/gateway/storage/app/orbit/ca',
        '/home/orbit/orbit/apps/gateway/storage/app/orbit/certs',
        '/home/orbit/orbit/apps/gateway/storage/app/orbit/keys',
    ];

    $assertions = collect($forbiddenPaths)
        ->map(fn (string $path): string => sprintf('test ! -e %s || { echo "FORBIDDEN PATH PRESENT: %s"; exit 1; }', escapeshellarg($path), $path))
        ->implode('; ');

    $process = new Process([
        'docker',
        'run',
        '--rm',
        $image,
        'bash',
        '-c',
        sprintf('set -e; %s; echo OK', $assertions),
    ]);

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())
        ->toContain('OK');
});

it('provides Docker CLI and host PHP CLI baseline without ad hoc helper tools in the topology image', function (): void {
    $image = DockerTopologyBuilder::runtimeImage();

    $availability = new Process([
        'docker',
        'image',
        'inspect',
        $image,
    ]);

    $availability->run();

    if ($availability->getExitCode() !== 0) {
        test()->markTestSkipped("Docker runtime image {$image} is not available.");
    }

    $label = new Process([
        'docker',
        'image',
        'inspect',
        '--format',
        '{{ index .Config.Labels "org.orbit.e2e.substrate" }}',
        $image,
    ]);

    $label->run();

    if (trim($label->getOutput()) !== 'docker-first') {
        test()->markTestSkipped("Docker runtime image {$image} was not built from the Docker-first topology Dockerfile.");
    }

    $sourceLabel = new Process([
        'docker',
        'image',
        'inspect',
        '--format',
        '{{ index .Config.Labels "org.orbit.e2e.source" }}',
        $image,
    ]);

    $sourceLabel->run();

    if (trim($sourceLabel->getOutput()) !== 'prepared-checkout') {
        test()->markTestSkipped("Docker runtime image {$image} was not built from the source-less topology Dockerfile.");
    }

    $process = new Process([
        'docker',
        'run',
        '--rm',
        $image,
        'bash',
        '-c',
        implode(' && ', [
            'command -v docker',
            'command -v php',
            'php --version | grep -q "^PHP 8[.]5[.]"',
            'php -r \'foreach (["pdo_sqlite", "openssl", "curl", "mbstring", "json", "xml"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, $extension.PHP_EOL); exit(1); } }\'',
            '! command -v python3',
            '! command -v sqlite3',
            '! command -v composer',
            '! command -v caddy',
            '! command -v php-fpm',
            '! systemctl status caddy >/tmp/orbit-caddy-status.log 2>&1',
            '! command -v orbit',
            '! test -e /opt/orbit-source',
            '! test -f /home/operator/orbit/artisan',
            '! test -f /home/operator/orbit/apps/gateway/artisan',
            '! test -f /home/orbit/orbit/artisan',
            '! test -f /home/orbit/orbit/apps/gateway/artisan',
            'echo OK',
        ]),
    ]);

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())
        ->toContain('OK');
});
