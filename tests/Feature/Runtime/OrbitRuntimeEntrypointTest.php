<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('starts the gateway API HTTP server on port 8080 before running the scheduler when ORBIT_IS_GATEWAY is set', function (): void {
    $root = sys_get_temp_dir().'/orbit-runtime-gateway-server-'.bin2hex(random_bytes(6));
    $source = "{$root}/source";
    $bin = "{$root}/bin";
    $capture = "{$root}/capture";

    mkdir($source, recursive: true);
    mkdir($bin, recursive: true);

    file_put_contents("{$source}/artisan", "<?php\n");

    /*
     * The fake `php` records every invocation to a capture log. The scheduler
     * call is run via `exec` from the entrypoint, so it is the final invocation.
     * The background gateway HTTP server is launched with `&`, so it is logged
     * separately before the scheduler exits.
     */
    file_put_contents("{$bin}/php", <<<'BASH'
#!/usr/bin/env bash
{
    printf 'php_argv=%s\n' "$*"
} >> "$PHP_CAPTURE"

if [ "${1:-}" = "" ]; then
    exit 0
fi

# Match the "serve" subcommand so the background process exits quickly.
for arg do
    case "$arg" in
        serve)
            exit 0
            ;;
    esac
done

exit 0
BASH);
    chmod("{$bin}/php", 0755);

    $entrypoint = base_path('docker/orbit-runtime/entrypoint.sh');

    try {
        $process = new Process(
            ['bash', $entrypoint, 'sleep', 'infinity'],
            null,
            [
                'ORBIT_SOURCE_PATH' => $source,
                'ORBIT_IS_GATEWAY' => '1',
                'PATH' => $bin.':/usr/bin:/bin',
                'PHP_CAPTURE' => $capture,
            ],
        );

        $process->setTimeout(15);
        $process->run();

        $logged = file_exists($capture) ? file_get_contents($capture) : '';

        expect($logged)
            ->toContain('serve')
            ->toContain('--host=0.0.0.0')
            ->toContain('--port=8080')
            ->toContain('--no-reload')
            ->toContain('orbit-scheduler');
    } finally {
        (new Process(['rm', '-rf', $root]))->run();
    }
});

it('does not start the gateway HTTP server when ORBIT_IS_GATEWAY is not set', function (): void {
    $root = sys_get_temp_dir().'/orbit-runtime-non-gateway-'.bin2hex(random_bytes(6));
    $bin = "{$root}/bin";

    mkdir($bin, recursive: true);

    file_put_contents("{$bin}/php", <<<'BASH'
#!/usr/bin/env bash
printf 'should not run for non-gateway sleep\n' >&2
exit 99
BASH);
    chmod("{$bin}/php", 0755);

    $entrypoint = base_path('docker/orbit-runtime/entrypoint.sh');

    try {
        $process = new Process(
            ['bash', $entrypoint, 'true'],
            null,
            [
                'PATH' => $bin.':/usr/bin:/bin',
            ],
        );

        $process->run();

        expect($process->getExitCode())->toBe(0);
    } finally {
        (new Process(['rm', '-rf', $root]))->run();
    }
});

it('exposes the gateway API host and port through ORBIT_API_HOST / ORBIT_API_PORT overrides', function (): void {
    $entrypoint = file_get_contents(base_path('docker/orbit-runtime/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('ORBIT_API_HOST')
        ->toContain('ORBIT_API_PORT')
        ->toContain('start_gateway_http_server');
});
