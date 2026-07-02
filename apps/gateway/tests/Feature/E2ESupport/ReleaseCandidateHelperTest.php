<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('fails build preflight when HEAD does not match origin main', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'preflight');

    try {
        $root = release_candidate_prepare_root(temp: $temp);

        $process = release_candidate_process(
            arguments: ['build'],
            env: release_candidate_process_env(root: $root, overrides: [
                'ORBIT_TEST_ORIGIN_MAIN_COMMIT' => str_repeat('b', 40),
            ]),
        );

        expect($process->getExitCode())
            ->toBe(1, $process->getOutput().$process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('Candidate artifacts must be built from the pushed origin/main commit.')
            ->and("{$root}/.orbit/release-candidates/latest")
            ->not->toBeFile();
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('fails build preflight when the tracked tree is dirty', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'dirty-tree');

    try {
        $root = release_candidate_prepare_root(temp: $temp);

        $process = release_candidate_process(
            arguments: ['build'],
            env: release_candidate_process_env(root: $root, overrides: [
                'ORBIT_TEST_GIT_STATUS' => ' M bin/orbit-release-candidate',
            ]),
        );

        expect($process->getExitCode())
            ->toBe(1, $process->getOutput().$process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('clean checkout')
            ->and("{$root}/.orbit/release-candidates/latest")
            ->not->toBeFile();
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('writes durable candidate state with sha256 keys and a latest pointer during a stubbed build', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'build');

    try {
        $root = release_candidate_prepare_root(temp: $temp);

        $process = release_candidate_process(
            arguments: ['build'],
            env: release_candidate_process_env(root: $root),
        );

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

        $latestPointer = "{$root}/.orbit/release-candidates/latest";

        expect($latestPointer)->toBeFile();

        $buildId = trim((string) file_get_contents($latestPointer));

        expect($buildId)->toMatch('/^\d{8}T\d{6}Z-[0-9a-f]+$/');

        $stateDir = "{$root}/.orbit/release-candidates/{$buildId}";

        foreach ([
            'candidate.env',
            'gateway-image-push.log',
            'orbit-linux-x64',
            'orbit-macos-arm64',
            'orbit-release-manifest.candidate.json',
        ] as $stateFile) {
            expect("{$stateDir}/{$stateFile}")->toBeFile();
        }

        $state = release_candidate_parse_state(path: "{$stateDir}/candidate.env");

        expect($state)->toMatchArray([
            'version' => '0.1.200',
            'build_id' => $buildId,
            'commit' => str_repeat('a', 40),
            'candidate_image' => "ghcr.io/hardimpactdev/orbit-gateway:0.1.200-candidate-{$buildId}",
            'candidate_dir' => $stateDir,
            'candidate_prefix' => "candidates/{$buildId}",
            'candidate_channel' => 'live-test',
            'candidate_asset_base_url' => "https://s3.example.test/orbit/candidates/{$buildId}",
            'gateway_digest' => 'sha256:'.str_repeat('ab', 32),
            'candidate_channel_manifest_url' => 'https://s3.example.test/orbit/channels/live-test/orbit-release-manifest.json',
            'sha256_linux_amd64' => hash_file('sha256', "{$stateDir}/orbit-linux-x64"),
            'sha256_darwin_arm64' => hash_file('sha256', "{$stateDir}/orbit-macos-arm64"),
        ]);

        expect($process->getOutput())
            ->toContain(
                'Candidate channel manifest: https://s3.example.test/orbit/channels/live-test/orbit-release-manifest.json',
            );

        $stubLog = (string) file_get_contents("{$root}/stub.log");

        expect($stubLog)
            ->toContain('Storage::disk')
            ->toContain('orbit-release-manifest')
            ->toContain('orbit-build-cli-binary mac arm 0.1.200')
            ->toContain('orbit-build-cli-binary linux x64 0.1.200')
            ->not->toContain('release create')
            ->not->toContain('push origin')
            ->not->toContain('imagetools create');

        expect((string) file_get_contents("{$stateDir}/candidate.env"))
            ->not->toContain('stub-ghcr-token')->and((string) file_get_contents("{$stateDir}/gateway-image-push.log"))
            ->not->toContain('stub-ghcr-token');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('prints eval-able exports for candidate state and fails cleanly with no state', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'env');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $missing = release_candidate_process(arguments: ['env'], env: $env);

        expect($missing->getExitCode())
            ->not
            ->toBe(0)
            ->and($missing->getErrorOutput())
            ->toContain('No release candidate state');

        $buildId = '20260701T000000Z-abcdef12';
        $stateDir = release_candidate_write_state(root: $root, buildId: $buildId, pointLatest: true);

        $process = release_candidate_process(arguments: ['env'], env: $env);

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('export version=9.9.9')
            ->toContain("export build_id={$buildId}")
            ->toContain("export candidate_dir={$stateDir}")
            ->toContain('export sha256_linux_amd64=')
            ->toContain('export sha256_darwin_arm64=');

        $eval = new Process(
            [
                'bash',
                '-c',
                'eval "$("$0" env)" && printf %s "$candidate_image"',
                repo_path('bin/orbit-release-candidate'),
            ],
            repo_path(),
            $env,
        );
        $eval->run();

        expect($eval->getExitCode())
            ->toBe(0, $eval->getOutput().$eval->getErrorOutput())
            ->and($eval->getOutput())
            ->toBe("ghcr.io/hardimpactdev/orbit-gateway:9.9.9-candidate-{$buildId}");
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('verifies intact artifacts and fails naming the tampered sha256 key', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'verify');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);
        $buildId = '20260701T000000Z-abcdef12';
        $stateDir = release_candidate_write_state(root: $root, buildId: $buildId, pointLatest: true);

        $pass = release_candidate_process(
            arguments: ['verify', '--release-image=ghcr.io/hardimpactdev/orbit-gateway:9.9.9'],
            env: $env,
        );

        expect($pass->getExitCode())
            ->toBe(0, $pass->getOutput().$pass->getErrorOutput())
            ->and($pass->getOutput())
            ->toContain('PASS sha256_linux_amd64')
            ->toContain('PASS sha256_darwin_arm64')
            ->toContain('PASS gateway_digest');

        file_put_contents("{$stateDir}/orbit-linux-x64", "tampered\n", FILE_APPEND);

        $fail = release_candidate_process(arguments: ['verify'], env: $env);

        expect($fail->getExitCode())
            ->not
            ->toBe(0)
            ->and($fail->getOutput())
            ->toContain('FAIL sha256_linux_amd64')
            ->toContain('PASS sha256_darwin_arm64');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('reports an imagetools inspect failure as a gateway_digest mismatch without aborting the verify report', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'verify-inspect');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root, overrides: [
            'ORBIT_TEST_IMAGETOOLS_FAIL' => '1',
        ]);
        release_candidate_write_state(root: $root, buildId: '20260701T000000Z-abcdef12', pointLatest: true);

        $process = release_candidate_process(
            arguments: ['verify', '--release-image=ghcr.io/hardimpactdev/orbit-gateway:9.9.9'],
            env: $env,
        );

        expect($process->getExitCode())
            ->toBe(1, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PASS sha256_linux_amd64')
            ->toContain('PASS sha256_darwin_arm64')
            ->toContain('FAIL gateway_digest: imagetools inspect failed for ghcr.io/hardimpactdev/orbit-gateway:9.9.9')
            ->and($process->getErrorOutput())
            ->toContain('verify failed with 1 mismatch(es)');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('rejects --build-id values that do not match the build id pattern', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'build-id-pattern');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        mkdir("{$root}/.orbit/release-candidates", recursive: true);
        mkdir("{$root}/evil", recursive: true);
        file_put_contents("{$root}/evil/candidate.env", "version=6.6.6\n");

        $traversalEnv = release_candidate_process(arguments: ['env', '--build-id=../../evil'], env: $env);
        $traversalVerify = release_candidate_process(arguments: ['verify', '--build-id=../../evil'], env: $env);

        expect($traversalEnv->getExitCode())
            ->toBe(1, $traversalEnv->getOutput().$traversalEnv->getErrorOutput())
            ->and($traversalEnv->getOutput())
            ->not
            ->toContain('6.6.6')
            ->and($traversalEnv->getErrorOutput())
            ->toContain('../../evil')
            ->toContain('[0-9]{8}T[0-9]{6}Z-[0-9a-f]+')
            ->and($traversalVerify->getExitCode())
            ->toBe(1, $traversalVerify->getOutput().$traversalVerify->getErrorOutput())
            ->and($traversalVerify->getErrorOutput())
            ->toContain('[0-9]{8}T[0-9]{6}Z-[0-9a-f]+');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('resolves --build-id ahead of the latest pointer', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'build-id');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        release_candidate_write_state(
            root: $root,
            buildId: '20260701T000000Z-aaaaaaaa',
            overrides: ['version' => '1.1.1'],
            pointLatest: true,
        );
        release_candidate_write_state(
            root: $root,
            buildId: '20260702T000000Z-bbbbbbbb',
            overrides: ['version' => '2.2.2'],
        );

        $latest = release_candidate_process(arguments: ['env'], env: $env);
        $named = release_candidate_process(
            arguments: ['env', '--build-id=20260702T000000Z-bbbbbbbb'],
            env: $env,
        );
        $unknown = release_candidate_process(
            arguments: ['env', '--build-id=20990101T000000Z-ffffffff'],
            env: $env,
        );

        expect($latest->getExitCode())
            ->toBe(0, $latest->getOutput().$latest->getErrorOutput())
            ->and($latest->getOutput())
            ->toContain('export version=1.1.1')
            ->and($named->getExitCode())
            ->toBe(0, $named->getOutput().$named->getErrorOutput())
            ->and($named->getOutput())
            ->toContain('export version=2.2.2')
            ->toContain('export build_id=20260702T000000Z-bbbbbbbb')
            ->and($unknown->getExitCode())
            ->not
            ->toBe(0)
            ->and($unknown->getErrorOutput())
            ->toContain('20990101T000000Z-ffffffff');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

function release_candidate_make_temp_dir(string $suffix): string
{
    $temp = sys_get_temp_dir().'/orbit-release-candidate-'.$suffix.'-'.bin2hex(random_bytes(6));

    mkdir($temp, recursive: true);

    return $temp;
}

function release_candidate_remove_temp_dir(string $path): void
{
    if ($path === '' || ! str_contains($path, '/orbit-release-candidate-')) {
        return;
    }

    new Process(['rm', '-rf', $path])->run();
}

/**
 * Builds a fake repository root whose bin/ directory doubles as the stub PATH
 * for docker, gh, git, and the bin helper launchers the release-candidate
 * helper shells out to.
 */
function release_candidate_prepare_root(string $temp): string
{
    $root = "{$temp}/root";

    mkdir("{$root}/bin", recursive: true);
    mkdir("{$root}/home", recursive: true);
    file_put_contents("{$root}/release.env", "ORBIT_TEST_RELEASE_ENV=sourced\n");
    file_put_contents("{$root}/stub.log", '');

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'git', body: <<<'BASH'
        printf 'git %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        case "$*" in
            'rev-parse HEAD') printf '%s\n' "${ORBIT_TEST_HEAD_COMMIT}" ;;
            'rev-parse --short HEAD') printf '%s\n' "${ORBIT_TEST_HEAD_COMMIT:0:8}" ;;
            'ls-remote origin refs/heads/main') printf '%s\trefs/heads/main\n' "${ORBIT_TEST_ORIGIN_MAIN_COMMIT}" ;;
            'status --porcelain') [ -z "${ORBIT_TEST_GIT_STATUS:-}" ] || printf '%s\n' "${ORBIT_TEST_GIT_STATUS}" ;;
            *) exit 1 ;;
        esac
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'docker', body: <<<'BASH'
        printf 'docker %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        if [ "$1" = 'buildx' ] && [ "$2" = 'imagetools' ] && [ "$3" = 'inspect' ]; then
            if [ -n "${ORBIT_TEST_IMAGETOOLS_FAIL:-}" ]; then
                printf 'ERROR: %s: not found\n' "$4" >&2
                exit 1
            fi
            printf 'Name:      %s\n' "$4"
            printf 'MediaType: application/vnd.oci.image.index.v1+json\n'
            printf 'Digest:    %s\n' "${ORBIT_TEST_GATEWAY_DIGEST}"
            exit 0
        fi
        if [ "$1" = 'buildx' ] && [ "$2" = 'build' ]; then
            exit 0
        fi
        if [ "$1" = 'push' ]; then
            printf 'The push refers to repository [ghcr.io/hardimpactdev/orbit-gateway]\n'
            printf 'candidate: digest: %s size: 4287\n' "${ORBIT_TEST_GATEWAY_DIGEST}"
            exit 0
        fi
        exit 1
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'gh', body: <<<'BASH'
        printf 'gh %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        case "$1 $2" in
            'auth status') exit 0 ;;
            'auth token') printf 'stub-ghcr-token\n' ;;
            'api user') printf 'stub-user\n' ;;
            *) exit 1 ;;
        esac
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-version', body: <<<'BASH'
        printf 'orbit-version %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        printf '0.1.200\n'
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-gateway-artisan', body: <<<'BASH'
        printf 'orbit-gateway-artisan %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        for arg in "$@"; do
            case "$arg" in
                *'artifacts.base_url'*)
                    printf 'https://s3.example.test/orbit\n'
                    exit 0
                    ;;
                *'filesystems.disks.orbit-artifacts'*)
                    printf 'yes\n'
                    exit 0
                    ;;
            esac
        done
        exit 0
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-build-cli-binary', body: <<<'BASH'
        printf 'orbit-build-cli-binary %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        root="${ORBIT_RELEASE_CANDIDATE_ROOT:?}"
        case "$1 $2" in
            'mac arm')
                mkdir -p "${root}/apps/cli/builds/dist/mac"
                printf 'stub-mac-arm-binary\n' > "${root}/apps/cli/builds/dist/mac/mac-arm"
                ;;
            'linux x64')
                mkdir -p "${root}/apps/cli/builds/dist/linux"
                printf 'stub-linux-x64-binary\n' > "${root}/apps/cli/builds/dist/linux/linux-x64"
                ;;
            *) exit 1 ;;
        esac
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-release-manifest', body: <<<'BASH'
        printf 'orbit-release-manifest %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        output=''
        for arg in "$@"; do
            case "$arg" in
                --output=*) output="${arg#--output=}" ;;
            esac
        done
        [ -n "$output" ] || exit 1
        printf '{"schema_version":1,"source":"topology-candidate","stub":true}\n' > "$output"
        BASH);

    return $root;
}

function release_candidate_write_stub(string $binDir, string $name, string $body): void
{
    $path = "{$binDir}/{$name}";

    file_put_contents($path, "#!/usr/bin/env bash\nset -euo pipefail\n{$body}\n");
    chmod($path, 0o755);
}

/**
 * @param  array<string, string|false>  $overrides
 *
 * @return array<string, string|false>
 */
function release_candidate_process_env(string $root, array $overrides = []): array
{
    return array_merge([
        'PATH' => "{$root}/bin:".getenv('PATH'),
        'ORBIT_RELEASE_CANDIDATE_ROOT' => $root,
        'ORBIT_RELEASE_ENV_FILE' => "{$root}/release.env",
        'ORBIT_PRIMARY_CHECKOUT' => $root,
        'HOME' => "{$root}/home",
        'STUB_LOG' => "{$root}/stub.log",
        'ORBIT_TEST_HEAD_COMMIT' => str_repeat('a', 40),
        'ORBIT_TEST_ORIGIN_MAIN_COMMIT' => str_repeat('a', 40),
        'ORBIT_TEST_GATEWAY_DIGEST' => 'sha256:'.str_repeat('ab', 32),
        'ORBIT_RELEASE_CANDIDATE_CHANNEL' => false,
    ], $overrides);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function release_candidate_process(array $arguments, array $env): Process
{
    $process = new Process(
        [repo_path('bin/orbit-release-candidate'), ...$arguments],
        repo_path(),
        $env,
    );

    $process->run();

    return $process;
}

/**
 * Writes a durable release-candidate state directory the way `build` records
 * it, so `env` and `verify` behavior can be exercised without a build run.
 *
 * @param  array<string, string>  $overrides
 */
function release_candidate_write_state(
    string $root,
    string $buildId,
    array $overrides = [],
    bool $pointLatest = false,
): string {
    $stateDir = "{$root}/.orbit/release-candidates/{$buildId}";

    mkdir($stateDir, recursive: true);

    file_put_contents("{$stateDir}/orbit-linux-x64", "linux-artifact-{$buildId}\n");
    file_put_contents("{$stateDir}/orbit-macos-arm64", "mac-artifact-{$buildId}\n");
    file_put_contents("{$stateDir}/orbit-release-manifest.candidate.json", "{\"stub\":true}\n");
    file_put_contents("{$stateDir}/gateway-image-push.log", "candidate: digest: sha256:stub size: 1\n");

    $values = array_merge([
        'version' => '9.9.9',
        'build_id' => $buildId,
        'commit' => str_repeat('a', 40),
        'candidate_image' => "ghcr.io/hardimpactdev/orbit-gateway:9.9.9-candidate-{$buildId}",
        'candidate_dir' => $stateDir,
        'candidate_prefix' => "candidates/{$buildId}",
        'candidate_channel' => 'live-test',
        'candidate_asset_base_url' => "https://s3.example.test/orbit/candidates/{$buildId}",
        'gateway_digest' => 'sha256:'.str_repeat('ab', 32),
        'candidate_channel_manifest_url' => 'https://s3.example.test/orbit/channels/live-test/orbit-release-manifest.json',
        'sha256_linux_amd64' => (string) hash_file('sha256', "{$stateDir}/orbit-linux-x64"),
        'sha256_darwin_arm64' => (string) hash_file('sha256', "{$stateDir}/orbit-macos-arm64"),
    ], $overrides);

    $lines = '';

    foreach ($values as $key => $value) {
        $lines .= "{$key}={$value}\n";
    }

    file_put_contents("{$stateDir}/candidate.env", $lines);

    if ($pointLatest) {
        file_put_contents("{$root}/.orbit/release-candidates/latest", "{$buildId}\n");
    }

    return $stateDir;
}

/**
 * @return array<string, string>
 */
function release_candidate_parse_state(string $path): array
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
