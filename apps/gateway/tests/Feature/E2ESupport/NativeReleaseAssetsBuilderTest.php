<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('rejects non-Darwin and non-arm64 build hosts before building', function (string $os, string $arch): void {
    $fixture = native_release_assets_fixture(os: $os, arch: $arch);

    try {
        $process = native_release_assets_process($fixture);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain($os !== 'Darwin' ? 'requires Darwin' : 'requires arm64')
            ->and((string) file_get_contents($fixture['stub_log']))
            ->not->toContain('orbit-build-agent-binary');
    } finally {
        native_release_assets_remove_fixture($fixture['root']);
    }
})->with([
    ['Linux', 'x86_64'],
    ['Darwin', 'x86_64'],
]);

it('writes an atomic Darwin arm64 Agent bundle with a rigid checksum manifest', function (): void {
    $fixture = native_release_assets_fixture();

    try {
        $process = native_release_assets_process($fixture);

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and("{$fixture['output']}/orbit-agent-macos-arm64")
            ->toBeFile()
            ->and("{$fixture['output']}/native-assets.env")
            ->toBeFile()
            ->and(glob("{$fixture['output']}.staging.*") ?: [])
            ->toBeEmpty();

        $manifest = native_release_assets_parse_manifest("{$fixture['output']}/native-assets.env");
        expect($manifest)
            ->toMatchArray([
                'schema' => '2',
                'commit' => str_repeat('a', 40),
                'version' => '0.1.200',
                'builder_os' => 'Darwin',
                'builder_arch' => 'arm64',
                'sha256_agent_darwin_arm64' => hash_file('sha256', "{$fixture['output']}/orbit-agent-macos-arm64"),
                'sha256_desktop_darwin_arm64' => hash_file('sha256', "{$fixture['output']}/Orbit.app.tar.gz"),
                'sha256_dmg_darwin_arm64' => hash_file('sha256', "{$fixture['output']}/Orbit.dmg"),
                'desktop_signature_darwin_arm64' => 'dW50cnVzdGVkLXRlc3Qtc2lnbmF0dXJl',
            ])
            ->and("{$fixture['output']}/Orbit.app.tar.gz")
            ->toBeFile()
            ->and("{$fixture['output']}/Orbit.app.tar.gz.sig")
            ->toBeFile()
            ->and("{$fixture['output']}/Orbit.dmg")
            ->toBeFile();
    } finally {
        native_release_assets_remove_fixture($fixture['root']);
    }
});

it('rejects a dirty native release checkout before building', function (): void {
    $fixture = native_release_assets_fixture();

    try {
        $process = native_release_assets_process($fixture, env: [
            'ORBIT_TEST_GIT_STATUS' => ' M VERSION',
        ]);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('native release source checkout must be clean')
            ->and((string) file_get_contents($fixture['stub_log']))
            ->not->toContain('orbit-build-agent-binary')->and($fixture['output'])
            ->not->toBeDirectory();
    } finally {
        native_release_assets_remove_fixture($fixture['root']);
    }
});

it('rejects a non-Mach-O arm64 Agent build result', function (): void {
    $fixture = native_release_assets_fixture();

    try {
        $process = native_release_assets_process($fixture, env: [
            'ORBIT_TEST_FILE_RESULT' => 'ELF 64-bit LSB executable, x86-64',
        ]);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('native Agent artifact is not a Mach-O arm64 executable')
            ->and($fixture['output'])
            ->not
            ->toBeDirectory()
            ->and(glob("{$fixture['output']}.staging.*") ?: [])
            ->toBeEmpty();
    } finally {
        native_release_assets_remove_fixture($fixture['root']);
    }
});

it('passes the release updater pubkey through TAURI_CONFIG', function (): void {
    $source = (string) file_get_contents(repo_path('bin/orbit-build-desktop-bundle'));

    expect($source)
        ->toContain('ORBIT_TAURI_UPDATER_PUBKEY')
        ->toContain('TAURI_CONFIG')
        ->toContain('plugins":{"updater":{"pubkey":"%s"}}');
});

it('fails closed when the desktop signing key is missing', function (): void {
    $root = sys_get_temp_dir().'/orbit-desktop-bundle-'.bin2hex(random_bytes(6));
    mkdir($root, recursive: true);

    try {
        $process = new Process(
            [repo_path('bin/orbit-build-desktop-bundle'), "--output={$root}"],
            repo_path(),
            [
                'TAURI_SIGNING_PRIVATE_KEY' => false,
                'ORBIT_TAURI_UPDATER_PUBKEY' => false,
            ],
        );
        $process->run();

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('TAURI_SIGNING_PRIVATE_KEY');
    } finally {
        native_release_assets_remove_fixture($root);
    }
});

it('rejects an already-existing native release output directory', function (): void {
    $fixture = native_release_assets_fixture();

    try {
        mkdir($fixture['output'], recursive: true);

        $process = native_release_assets_process($fixture);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('native release output already exists')
            ->and((string) file_get_contents($fixture['stub_log']))
            ->not->toContain('orbit-build-agent-binary');
    } finally {
        native_release_assets_remove_fixture($fixture['root']);
    }
});

/**
 * @return array{root: string, output: string, stub_log: string}
 */
function native_release_assets_fixture(string $os = 'Darwin', string $arch = 'arm64'): array
{
    $root = sys_get_temp_dir().'/orbit-native-release-assets-'.bin2hex(random_bytes(6));
    $binDir = $root.'/bin';

    mkdir($binDir, recursive: true);
    file_put_contents("{$root}/stub.log", '');

    native_release_assets_write_stub($binDir, 'uname', <<<'BASH'
        printf 'uname %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        case "$1" in
            -s) printf '%s\n' "${ORBIT_TEST_UNAME_S}" ;;
            -m) printf '%s\n' "${ORBIT_TEST_UNAME_M}" ;;
            *) exit 1 ;;
        esac
        BASH);

    native_release_assets_write_stub($binDir, 'git', <<<'BASH'
        printf 'git %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        while [ "${1:-}" = '-C' ]; do
            shift 2
        done
        case "$*" in
            'status --porcelain') [ -z "${ORBIT_TEST_GIT_STATUS:-}" ] || printf '%s\n' "${ORBIT_TEST_GIT_STATUS}" ;;
            'rev-parse HEAD') printf '%s\n' "${ORBIT_TEST_HEAD_COMMIT}" ;;
            *) exit 1 ;;
        esac
        BASH);

    native_release_assets_write_stub($binDir, 'file', <<<'BASH'
        printf 'file %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        printf '%s: %s\n' "$1" "${ORBIT_TEST_FILE_RESULT}"
        BASH);

    native_release_assets_write_stub($binDir, 'orbit-version', <<<'BASH'
        printf 'orbit-version %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        printf '0.1.200\n'
        BASH);

    native_release_assets_write_stub($binDir, 'orbit-build-agent-binary', <<<'BASH'
        printf 'orbit-build-agent-binary %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        root="${ORBIT_NATIVE_RELEASE_ROOT:?}"
        mkdir -p "${root}/apps/agent/builds/dist/mac"
        printf 'stub-agent-macos-arm64-binary\n' > "${root}/apps/agent/builds/dist/mac/mac-arm"
        chmod 0755 "${root}/apps/agent/builds/dist/mac/mac-arm"
        BASH);

    native_release_assets_write_stub($binDir, 'orbit-build-desktop-bundle', <<<'BASH'
        printf 'orbit-build-desktop-bundle %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        output=""
        for argument in "$@"; do
            case "$argument" in
                --output=*) output="${argument#*=}" ;;
            esac
        done
        mkdir -p "$output"
        printf 'stub-desktop-archive\n' > "${output}/Orbit.app.tar.gz"
        printf 'dW50cnVzdGVkLXRlc3Qtc2lnbmF0dXJl\n' > "${output}/Orbit.app.tar.gz.sig"
        printf 'stub-desktop-dmg\n' > "${output}/Orbit.dmg"
        BASH);

    return [
        'root' => $root,
        'output' => $root.'/native-assets',
        'stub_log' => $root.'/stub.log',
        'os' => $os,
        'arch' => $arch,
    ];
}

function native_release_assets_remove_fixture(string $root): void
{
    if (
        $root === ''
        || ! str_contains($root, '/orbit-native-release-assets-')
        && ! str_contains($root, '/orbit-desktop-bundle-')
    ) {
        return;
    }

    new Process(['rm', '-rf', $root])->run();
}

function native_release_assets_write_stub(string $binDir, string $name, string $body): void
{
    $path = "{$binDir}/{$name}";

    file_put_contents($path, "#!/usr/bin/env bash\nset -euo pipefail\n{$body}\n");
    chmod($path, 0o755);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function native_release_assets_process(array $fixture, array $arguments = [], array $env = []): Process
{
    $process = new Process(
        [
            repo_path('bin/orbit-build-native-release-assets'),
            ...(
                $arguments === []
                    ? [
                        "--output={$fixture['output']}",
                    ] : $arguments
            ),
        ],
        repo_path(),
        array_merge([
            'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            'ORBIT_NATIVE_RELEASE_ROOT' => $fixture['root'],
            'STUB_LOG' => $fixture['stub_log'],
            'ORBIT_TEST_UNAME_S' => $fixture['os'],
            'ORBIT_TEST_UNAME_M' => $fixture['arch'],
            'ORBIT_TEST_HEAD_COMMIT' => str_repeat('a', 40),
            'ORBIT_TEST_GIT_STATUS' => '',
            'ORBIT_TEST_FILE_RESULT' => 'Mach-O 64-bit executable arm64',
        ], $env),
    );
    $process->run();

    return $process;
}

/**
 * @return array<string, string>
 */
function native_release_assets_parse_manifest(string $path): array
{
    expect($path)->toBeFile();

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines === false ? [] : $lines as $line) {
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = $value;
    }

    return $values;
}
