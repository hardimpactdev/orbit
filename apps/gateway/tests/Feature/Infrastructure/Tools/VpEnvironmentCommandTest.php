<?php

declare(strict_types=1);

use App\Domain\Tools\VpEnvironment;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('only replaces the uncustomized npm-build forms', function (string $name, string $command, string $expected): void {
    $environment = new VpEnvironment('/tmp/orbit-vp-command', '0.3.3', '24.22.0', 'pnpm', '10.17.0');

    expect($environment->command($name, $command))->toEndWith('bash -eu -c '.escapeshellarg($expected));
})->with([
    ['npm-build', 'npm run build', 'pnpm run build'],
    ['npm-build', '/old/node /old/npm-cli.js run build', 'pnpm run build'],
    ['npm-build', 'node /old/npm-cli.js run build', 'pnpm run build'],
    ['npm-build', '/opt/node-v24/bin/node /opt/node-v24/lib/node_modules/npm/bin/npm-cli.js run build', 'pnpm run build'],
    ['frontend', '/old/node /old/npm-cli.js run build', '/old/node /old/npm-cli.js run build'],
    ['npm-build', 'node build.js', 'node build.js'],
    ['npm-build', 'npm run build -- --base=/customer/ && npm run build:ssr', 'npm run build -- --base=/customer/ && npm run build:ssr'],
    ['npm-build', '/old/node /old/npm-cli.js run build -- --base=/customer/', '/old/node /old/npm-cli.js run build -- --base=/customer/'],
    ['npm-build', 'node /old/npm-cli.js run build && npm run build:ssr', 'node /old/npm-cli.js run build && npm run build:ssr'],
]);

it('executes the whole customized npm-build command with its flags and SSR build', function (string $command, string $base): void {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().'/orbit-vp-command-'.Str::uuid();
    $files->makeDirectory($directory.'/bin', 0755, true);

    try {
        // Only the external vp/npm executables are faked. Bash executes the actual generated command.
        $files->put($directory.'/bin/vp', <<<'SH'
            #!/bin/sh
            set -eu
            test "$1" = env && test "$2" = exec
            test "$3" = --node && test "$4" = 24.22.0
            test "$5" = --package-manager && test "$6" = npm@11.12.0
            shift 6
            exec "$@"
            SH);
        $files->put($directory.'/bin/npm', <<<'SH'
            #!/bin/sh
            set -eu
            printf '<%s>' "$@" >> "$ORBIT_VP_BUILD_LOG"
            printf '\n' >> "$ORBIT_VP_BUILD_LOG"
            if [ "$2" = build:ssr ]; then
                : > "$ORBIT_VP_SSR_ASSET"
            fi
            SH);
        chmod($directory.'/bin/vp', 0755);
        chmod($directory.'/bin/npm', 0755);
        $environment = new VpEnvironment($directory, '0.3.3', '24.22.0', 'npm', '11.12.0');

        $process = new Process(['bash', '-eu', '-c', $environment->command('npm-build', $command)], $directory, [
            'PATH' => $directory.'/bin:'.getenv('PATH'),
            'ORBIT_VP_BUILD_LOG' => $directory.'/build.log',
            'ORBIT_VP_SSR_ASSET' => $directory.'/ssr.js',
        ]);
        $process->mustRun();

        expect($files->get($directory.'/build.log'))->toBe("<run><build><--><--base={$base}>\n<run><build:ssr>\n");
        expect($files->exists($directory.'/ssr.js'))->toBeTrue();
    } finally {
        $files->deleteDirectory($directory);
    }
})->with([
    ['npm run build -- --base=/customer/ && npm run build:ssr', '/customer/'],
    ['npm run build -- --base="/customer site/" && npm run build:ssr', '/customer site/'],
]);
