<?php

declare(strict_types=1);

use Orbit\Core\Php\PhpCliArtifactCatalog;

it('builds Orbit host PHP for every pinned patch, platform, and variant contract', function (): void {
    $scriptPath = repo_path('bin/orbit-build-php-cli-runtime');
    $patchPath = repo_path('packages/core/resources/php-cli/static-php-cli-2.8.5-pcov-static.patch');
    $handoffPath = repo_path('bin/orbit-php-cli-catalog-handoff');
    $buildCatalog = PhpCliArtifactCatalog::loadBuild();
    $runtimeCatalog = PhpCliArtifactCatalog::load();

    expect($scriptPath)
        ->toBeFile()
        ->and($patchPath)
        ->toBeFile()
        ->and($handoffPath)
        ->toBeFile();

    $script = file_get_contents($scriptPath);
    $patch = file_get_contents($patchPath);

    $sourcePathPatchPath = repo_path('packages/core/resources/php-cli/static-php-cli-2.8.5-pcov-source-path.patch');
    $configM4PatchPath = repo_path('packages/core/resources/php-cli/pcov-1.0.12-config-m4-php-version.patch');
    expect($sourcePathPatchPath)->toBeFile()->and($configM4PatchPath)->toBeFile();
    $sourcePathPatch = file_get_contents($sourcePathPatchPath);
    $configM4Patch = file_get_contents($configM4PatchPath);

    expect($script)
        ->toContain('coverage|standard')
        ->toContain('--variant')
        ->toContain('linux-x86_64|linux-aarch64|macos-aarch64|macos-x86_64')
        ->toContain('packages/core/resources/php-cli/artifact-catalog.build.json')
        ->toContain('php_source_sha256')
        ->toContain('static-php-cli-2.8.5-pcov-static.patch')
        ->toContain('static-php-cli-2.8.5-pcov-source-path.patch')
        ->toContain('pcov-1.0.12-config-m4-php-version.patch')
        ->toContain('static_php_cli_source_json_sha256')
        ->toContain('config/source.json')
        ->toContain('php-src/ext/pcov')
        ->toContain('pcov-upstream-extract')
        ->toContain('orbit-config-m4-patched.tgz')
        ->toContain('PCOV_PHP_VERSION')
        ->toContain('custom-url uses')
        ->toContain('PCOV config.m4 PHP_VERSION clobber patch validated')
        ->toContain('PCOV patches validated')
        // source/ does not exist until spc build; never patch post-download in-tree.
        ->not->toContain('source/php-src/ext/pcov/config.m4 missing after download')
        ->not->toMatch('/pcov_config_m4="\$\{work_directory\}\/source\/php-src\/ext\/pcov\/config\.m4"/')->toContain(
            '--with-hardcoded-ini=pcov.enabled=1',
        )->toContain('extension_loaded("pcov")')->toContain('function_exists("pcov\\\\start")')->toContain(
            'ldd',
        )->toContain('statically linked')->toContain('pcov.so')->toContain(
            'php-${php_version}-cli-${variant}-${platform}.tar.gz',
        )->toContain('.manifest.json')->toContain('"tool" => "php-cli"')->toContain('shasum -a 256')->toContain(
            '--custom-url',
        )->toContain('PCOV_SPC_SOURCE_NAME}:file://')->toContain('pcov.archive_sha256')->toContain(
            'pcov.version',
        )->toContain('pcov.url')->toContain('moving pecl.php.net/get/pcov')->toContain('./spc doctor')->toContain(
            '--auto-fix',
        )
        // Materialize the tarball listing before matching so pipefail cannot SIGPIPE tar.
        ->toContain('pcov_listing="$(tar -tzf "$pcov_upstream")"')->toContain(
            "printf '%s\\n' \"\$pcov_listing\" | grep -Eq",
        )
        ->not->toMatch('/tar -tzf "\$pcov_upstream" \| grep -q/')->toContain(
            'pcov_custom_url_args+=(--custom-url="${PCOV_SPC_SOURCE_NAME}:file://${pcov_patched_archive}")',
        )
        // Repack must archive the version dir only — never "." (which yields ./pcov-*/...).
        ->toContain('tar -czf "$pcov_patched_archive" -C "$pcov_extract" "pcov-${PCOV_VERSION}"')
        ->not->toContain('tar -czf "$pcov_patched_archive" -C "$pcov_extract" .')->toContain(
            'members must not start with ./',
        )->toContain('missing exact member pcov-${PCOV_VERSION}/config.m4');

    expect($patch)
        ->toContain('-            "shared"')
        ->toContain('+            "static"')
        ->toContain('"pcov"');

    expect($sourcePathPatch)
        ->toContain('--- a/config/source.json')
        ->toContain('+        "path": "php-src/ext/pcov"')
        ->toContain('"url": "https://pecl.php.net/get/pcov"');

    expect($configM4Patch)
        ->toContain('--- a/config.m4')
        ->toContain('-  PHP_VERSION=$($PHP_CONFIG --vernum)')
        ->toContain('+    PCOV_PHP_VERSION=$($PHP_CONFIG --vernum)')
        ->toContain('+    PCOV_PHP_VERSION=$PHP_VERSION_ID')
        ->toContain('+  if test -n "$PHP_CONFIG"; then')
        ->toContain('+  if test $PCOV_PHP_VERSION -gt 80099; then');

    $pcovPin = $buildCatalog->pcovPin();

    expect($buildCatalog->staticPhpCliVersion())
        ->toBe('2.8.5')
        ->and($buildCatalog->staticPhpCliExtJsonSha256())
        ->toBe('0fe7716d8cb199f34076c06a601b3ff9c8ffbce11d92a0bbd455d9d4f2d18d42')
        ->and($buildCatalog->staticPhpCliSourceJsonSha256())
        ->toBe('573dc8b14c1e9f7bf4623054064c27a0c09ff6a67ce262cf53a73ad91104b4a0')
        ->and($buildCatalog->patchPins())
        ->toMatchArray([
            '8.5' => '8.5.8',
            '8.4' => '8.4.21',
            '8.3' => '8.3.31',
        ])
        ->and($buildCatalog->pcovVersion())
        ->toBe('1.0.12')
        ->and($buildCatalog->pcovUrl())
        ->toBe('https://pecl.php.net/get/pcov-1.0.12.tgz')
        ->and($buildCatalog->pcovArchiveSha256())
        ->toBe('23255c8c9335a9636ccb743f5302436a97a582a0bbde9869485be911bbc15da8')
        ->and($pcovPin['config_m4_php_version_patch_sha256'] ?? null)
        ->toBe('c8aa7d1496e437549cbb13a5df734f83b26b59545eeb436184957e20db089951')
        ->and(hash_file('sha256', $configM4PatchPath))
        ->toBe('c8aa7d1496e437549cbb13a5df734f83b26b59545eeb436184957e20db089951')
        ->and($buildCatalog->pcovUrl())
        ->not
        ->toBe('https://pecl.php.net/get/pcov')
        ->and($buildCatalog->matrix())
        ->toHaveCount(9)
        ->and($buildCatalog->catalogRole())
        ->toBe('build')
        ->and($runtimeCatalog->usesCompatibilityContract())
        ->toBeTrue()
        ->and($runtimeCatalog->pcovVersion())
        ->toBe('1.0.12')
        ->and($runtimeCatalog->staticPhpCliSourceJsonSha256())
        ->toBe('573dc8b14c1e9f7bf4623054064c27a0c09ff6a67ce262cf53a73ad91104b4a0')
        ->and($runtimeCatalog->publicationStatus())
        ->toBe('compatibility');
});

it('applies and programmatically validates both PCOV SPC patches against official 2.8.5 configs', function (): void {
    $extSha = '0fe7716d8cb199f34076c06a601b3ff9c8ffbce11d92a0bbd455d9d4f2d18d42';
    $sourceSha = '573dc8b14c1e9f7bf4623054064c27a0c09ff6a67ce262cf53a73ad91104b4a0';
    $buildCatalog = PhpCliArtifactCatalog::loadBuild();

    expect($buildCatalog->staticPhpCliExtJsonSha256())
        ->toBe($extSha)
        ->and($buildCatalog->staticPhpCliSourceJsonSha256())
        ->toBe($sourceSha);

    $fixture = sys_get_temp_dir().'/orbit-spc-pcov-patches-'.bin2hex(random_bytes(4));
    mkdir($fixture.'/config', 0777, true);

    $extPath = $fixture.'/config/ext.json';
    $sourcePath = $fixture.'/config/source.json';

    // Fetch official SPC 2.8.5 configs (same URLs the builder uses) and verify pins.
    $extUrl = 'https://raw.githubusercontent.com/crazywhalecc/static-php-cli/2.8.5/config/ext.json';
    $sourceUrl = 'https://raw.githubusercontent.com/crazywhalecc/static-php-cli/2.8.5/config/source.json';

    $extBody = file_get_contents($extUrl);
    $sourceBody = file_get_contents($sourceUrl);
    expect($extBody)->not->toBeFalse()->and($sourceBody)->not->toBeFalse();

    file_put_contents($extPath, $extBody);
    file_put_contents($sourcePath, $sourceBody);

    expect(hash_file('sha256', $extPath))->toBe($extSha)->and(hash_file('sha256', $sourcePath))->toBe($sourceSha);

    $beforeSource = json_decode((string) file_get_contents($sourcePath), true, flags: JSON_THROW_ON_ERROR);
    expect($beforeSource['pcov'] ?? null)
        ->toBeArray()
        ->and(array_key_exists('path', $beforeSource['pcov']))
        ->toBeFalse();

    $staticPatch = repo_path('packages/core/resources/php-cli/static-php-cli-2.8.5-pcov-static.patch');
    $pathPatch = repo_path('packages/core/resources/php-cli/static-php-cli-2.8.5-pcov-source-path.patch');

    exec(
        sprintf(
            'patch -p1 -d %s < %s && patch -p1 -d %s < %s',
            escapeshellarg($fixture),
            escapeshellarg($staticPatch),
            escapeshellarg($fixture),
            escapeshellarg($pathPatch),
        ),
        $patchOut,
        $patchExit,
    );

    expect($patchExit)->toBe(0, implode("\n", $patchOut));

    // Same programmatic checks the builder runs after patching.
    $validate = sprintf(
        'php -r %s %s %s',
        escapeshellarg(
            '$ext = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);'
            .'$source = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);'
            .'$target = $ext["pcov"]["target"] ?? null;'
            .'$path = $source["pcov"]["path"] ?? null;'
            .'if ($target !== ["static"]) { fwrite(STDERR, "bad target\n"); exit(1); }'
            .'if ($path !== "php-src/ext/pcov") { fwrite(STDERR, "bad path\n"); exit(1); }'
            .'echo "ok\n";',
        ),
        escapeshellarg($extPath),
        escapeshellarg($sourcePath),
    );

    exec($validate.' 2>&1', $validateOut, $validateExit);
    expect($validateExit)->toBe(0)->and(implode("\n", $validateOut))->toContain('ok');

    $afterExt = json_decode((string) file_get_contents($extPath), true, flags: JSON_THROW_ON_ERROR);
    $afterSource = json_decode((string) file_get_contents($sourcePath), true, flags: JSON_THROW_ON_ERROR);

    expect($afterExt['pcov']['target'] ?? null)
        ->toBe(['static'])
        ->and($afterSource['pcov']['path'] ?? null)
        ->toBe('php-src/ext/pcov');
});

it('pins PCOV 1.0.12 with a verified archive identity in both catalogs', function (): void {
    $build = PhpCliArtifactCatalog::loadBuild();
    $runtime = PhpCliArtifactCatalog::load();

    foreach ([$build, $runtime] as $catalog) {
        $pin = $catalog->pcovPin();

        expect($pin['version'])
            ->toBe('1.0.12')
            ->and($pin['url'])
            ->toBe('https://pecl.php.net/get/pcov-1.0.12.tgz')
            ->and($pin['filename'])
            ->toBe('pcov-1.0.12.tgz')
            ->and($pin['spc_source_name'])
            ->toBe('pcov')
            ->and($pin['archive_sha256'])
            ->toBe('23255c8c9335a9636ccb743f5302436a97a582a0bbde9869485be911bbc15da8')
            ->and($pin['config_m4_php_version_patch_sha256'] ?? null)
            ->toBe('c8aa7d1496e437549cbb13a5df734f83b26b59545eeb436184957e20db089951')
            ->and($pin['url'])
            ->not->toBe('https://pecl.php.net/get/pcov');
    }
});

it('extracts, patches, validates, and repacks PCOV for custom-url without mutating upstream', function (): void {
    $patchPath = repo_path('packages/core/resources/php-cli/pcov-1.0.12-config-m4-php-version.patch');
    $expectedPatchSha = 'c8aa7d1496e437549cbb13a5df734f83b26b59545eeb436184957e20db089951';
    $expectedUpstreamSha = '23255c8c9335a9636ccb743f5302436a97a582a0bbde9869485be911bbc15da8';
    expect(hash_file('sha256', $patchPath))->toBe($expectedPatchSha);

    $fixture = sys_get_temp_dir().'/orbit-pcov-m4-'.bin2hex(random_bytes(4));
    mkdir($fixture, 0777, true);

    $upstream = $fixture.'/pcov-1.0.12.tgz';
    $archiveBody = file_get_contents('https://pecl.php.net/get/pcov-1.0.12.tgz');
    expect($archiveBody)->not->toBeFalse();
    file_put_contents($upstream, $archiveBody);
    expect(hash_file('sha256', $upstream))->toBe($expectedUpstreamSha);

    // Build the shell body without PHP sprintf — shell printf '%s\n' would be
    // consumed as sprintf placeholders and substitute later args (e.g. patch path
    // into first_member). Use only escapeshellarg substitution.
    $command = <<<'BASH'
        set -euo pipefail
        fixture=__FIXTURE__
        upstream=__UPSTREAM__
        patch=__PATCH__
        expected_upstream_sha=__EXPECTED_UPSTREAM_SHA__
        extract="${fixture}/pcov-upstream-extract"
        patched="${fixture}/pcov-1.0.12-orbit-config-m4-patched.tgz"
        mkdir -p "$extract"
        tar -xzf "$upstream" -C "$extract"
        config_m4="${extract}/pcov-1.0.12/config.m4"
        test -f "$config_m4"
        grep -Eq '^[[:space:]]*PHP_VERSION=\$\(\$PHP_CONFIG --vernum\)' "$config_m4"
        patch -p1 -d "$(dirname "$config_m4")" < "$patch"
        if grep -Eq '^[[:space:]]*PHP_VERSION=\$\(\$PHP_CONFIG --vernum\)' "$config_m4"; then
          echo "still clobbers" >&2
          exit 1
        fi
        grep -Eq '^[[:space:]]*PCOV_PHP_VERSION=\$\(\$PHP_CONFIG --vernum\)' "$config_m4"
        grep -Eq '^[[:space:]]*PCOV_PHP_VERSION=\$PHP_VERSION_ID' "$config_m4"
        grep -Eq 'if test -n "\$PHP_CONFIG"' "$config_m4"
        grep -Eq 'test \$PCOV_PHP_VERSION -gt 80099' "$config_m4"
        if grep -Eq 'test \$PHP_VERSION -gt 80099|test \$PHP_VERSION -lt 70' "$config_m4"; then
          echo "bare PHP_VERSION comparisons remain" >&2
          exit 1
        fi
        # Explicit version directory only — never "." (produces ./pcov-1.0.12/..., which SPC nests badly).
        tar -czf "$patched" -C "$extract" "pcov-1.0.12"
        listing="$(tar -tzf "$patched")"
        printf '%s\n' "$listing" | grep -Eq '^pcov-1.0.12/config\.m4$'
        if printf '%s\n' "$listing" | grep -Eq '^\./'; then
          echo "listing members start with ./ " >&2
          exit 1
        fi
        first_member="$(printf '%s\n' "$listing" | head -n 1)"
        case "$first_member" in
          ./*|.)
            echo "first member starts with ./: ${first_member}" >&2
            exit 1
            ;;
          pcov-1.0.12|pcov-1.0.12/*) ;;
          *)
            echo "first member not under pcov-1.0.12/: ${first_member}" >&2
            exit 1
            ;;
        esac
        upstream_sha="$(shasum -a 256 "$upstream" | awk '{print $1}')"
        patched_sha="$(shasum -a 256 "$patched" | awk '{print $1}')"
        test "$upstream_sha" = "$expected_upstream_sha"
        test "$upstream_sha" != "$patched_sha"
        test "$(shasum -a 256 "$upstream" | awk '{print $1}')" = "$upstream_sha"
        echo "repack_ok upstream=${upstream_sha} patched=${patched_sha} first=${first_member}"
        BASH;

    $command = str_replace(
        [
            '__FIXTURE__',
            '__UPSTREAM__',
            '__PATCH__',
            '__EXPECTED_UPSTREAM_SHA__',
        ],
        [
            escapeshellarg($fixture),
            escapeshellarg($upstream),
            escapeshellarg($patchPath),
            escapeshellarg($expectedUpstreamSha),
        ],
        $command,
    );

    exec('bash -c '.escapeshellarg($command).' 2>&1', $output, $exitCode);
    $joined = implode("\n", $output);
    $patchedPath = $fixture.'/pcov-1.0.12-orbit-config-m4-patched.tgz';

    expect($exitCode)
        ->toBe(0, $joined)
        ->and($joined)
        ->toContain('repack_ok upstream='.$expectedUpstreamSha)
        ->and(hash_file('sha256', $upstream))
        ->toBe($expectedUpstreamSha)
        ->and(is_file($patchedPath))
        ->toBeTrue()
        ->and(hash_file('sha256', $patchedPath))
        ->not->toBe($expectedUpstreamSha);
});

it('accepts legacy positional arguments as the standard variant', function (): void {
    $script = file_get_contents(repo_path('bin/orbit-build-php-cli-runtime'));

    expect($script)
        ->toContain('Legacy positional form')
        ->toContain('variant="standard"');
});

it('json_get resolves associative catalog keys that contain dots', function (): void {
    $catalogPath = repo_path(PhpCliArtifactCatalog::BUILD_CATALOG_RELATIVE_PATH);
    $buildCatalog = PhpCliArtifactCatalog::loadBuild();

    $expectedPhpSha = $buildCatalog->phpSourceSha256('8.5.8');
    $expectedPhp84Sha = $buildCatalog->phpSourceSha256('8.4.21');
    $expectedSpcSha = $buildCatalog->spcArchiveSha256('linux-x86_64');
    $expectedPcovVersion = $buildCatalog->pcovVersion();
    $expectedSqlite = $buildCatalog->sqliteVersion();

    // Execute the real json_get from the builder (not string-only assertions).
    // Naive explode(".", "php_source_sha256.8.5.8") would miss ["8.5.8"].
    $command = sprintf(
        'set -euo pipefail
ROOT_DIR=%s
CATALOG_PATH=%s
eval "$(sed -n \'/^json_get()/,/^}/p\' "${ROOT_DIR}/bin/orbit-build-php-cli-runtime")"
printf "php=%%s\\n" "$(json_get "php_source_sha256.8.5.8")"
printf "php84=%%s\\n" "$(json_get "php_source_sha256.8.4.21")"
printf "spc=%%s\\n" "$(json_get "spc_archive_sha256.linux-x86_64")"
printf "pcov=%%s\\n" "$(json_get "pcov.version")"
printf "sqlite=%%s\\n" "$(json_get "sqlite_version")"
if json_get "php_source_sha256.9.9.9" >/dev/null 2>&1; then
  echo "expected missing key to fail" >&2
  exit 1
fi
echo "missing_key_failed_closed"
',
        escapeshellarg(repo_path('.')),
        escapeshellarg($catalogPath),
    );

    exec('bash -c '.escapeshellarg($command).' 2>&1', $output, $exitCode);
    $joined = implode("\n", $output);

    expect($exitCode)
        ->toBe(0)
        ->and($joined)
        ->toContain("php={$expectedPhpSha}")
        ->and($joined)
        ->toContain("php84={$expectedPhp84Sha}")
        ->and($joined)
        ->toContain("spc={$expectedSpcSha}")
        ->and($joined)
        ->toContain("pcov={$expectedPcovVersion}")
        ->and($joined)
        ->toContain("sqlite={$expectedSqlite}")
        ->and($joined)
        ->toContain('missing_key_failed_closed');
});

it('catalog handoff targets the build catalog and supports runtime promotion', function (): void {
    $script = file_get_contents(repo_path('bin/orbit-php-cli-catalog-handoff'));

    expect($script)
        ->toContain('--manifest-dir')
        ->toContain('artifact-catalog.build.json')
        ->toContain('array_key_exists')
        ->toContain('--promote-runtime')
        ->toContain('published_count')
        ->toContain('Manifest tool must be php-cli')
        ->toContain('Manifest filename must be a non-empty string equal to')
        ->toContain('Manifest filename mismatch')
        ->toContain('Duplicate manifest cell')
        ->toContain('build catalog is missing artifact_base_url')
        // Missing filename must not be defaulted from patch/variant/platform.
        ->not->toContain('if (is_string($filename) && $filename !== "" && $filename !== $expected)')
        ->not->toContain('aws ')
        ->not->toContain('s3 cp')
        // Promotion must use the build catalog base URL, never a hardcoded fallback.
        ->not->toContain('s3.hardimpact.dev/orbit/runtimes/php-cli');
});
