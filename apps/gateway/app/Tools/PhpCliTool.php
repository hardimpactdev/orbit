<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\Php\PhpRuntimeCatalog;

final class PhpCliTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux', 'macos'];

    /** Base URL for the static-php-cli bulk preset downloads. */
    public const string BULK_BASE_URL = 'https://dl.static-php.dev/static-php-cli/bulk';

    public const string ORBIT_ARTIFACT_BASE_URL = 'https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6';

    private const string CurlRetryFlags = '--retry 5 --retry-delay 2 --retry-all-errors';

    /** Install root for static PHP binaries on the host. */
    public const string INSTALL_ROOT = '/opt/orbit/php';

    /**
     * Pinned patch versions for each supported minor.
     *
     * @var array<string,string>
     */
    public const array PATCH_PINS = [
        '8.5' => '8.5.8',
        '8.4' => '8.4.21',
        '8.3' => '8.3.31',
    ];

    /**
     * @var array<string, string>
     */
    private const array PHP_85_ARTIFACT_SHA256 = [
        'linux-x86_64' => '305f0a3d80907c72a5d7e2ce4b78e120a2bc53848b809fb16fb7511c1b00b828',
        'macos-aarch64' => 'fbd88fc83c699e2f65030f314937ec05edba41209bd38c8baa11b86f224a9329',
    ];

    public function slug(): string
    {
        return 'php-cli';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'safe-adopt'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return $this->buildScript('install');
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return $this->buildScript('update');
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => self::INSTALL_ROOT.'/8.5/bin/php',
            'version_command' => self::INSTALL_ROOT.'/8.5/bin/php --version',
        ];
    }

    private function buildScript(string $context): string
    {
        $base = self::BULK_BASE_URL;
        $orbitArtifactBase = self::ORBIT_ARTIFACT_BASE_URL;
        $root = self::INSTALL_ROOT;

        $versionBlocks = [];

        foreach (PhpRuntimeCatalog::SUPPORTED as $minor) {
            $patch = self::PATCH_PINS[$minor];
            $archive = "\${TEMP_DIR}/php-{$minor}.tar.gz";
            $extractDirectory = "\${TEMP_DIR}/php-{$minor}";
            $nextRuntime = "\${TEMP_DIR}/php-{$minor}.next";

            $artifactUrl = $minor === '8.5'
                ? "{$orbitArtifactBase}/php-{$patch}-cli-\${OS}-\${ARCH}.tar.gz"
                : "{$base}/php-{$patch}-cli-\${OS}-\${ARCH}.tar.gz";

            $checksumVerification = $minor === '8.5'
                ? <<<'BASH'
                    printf '%s  %s\n' "$PHP_85_SHA256" "$ARCHIVE" | shasum -a 256 -c -
                    BASH
                : '';

            $sqliteVerification = $minor === '8.5'
                ? <<<'BASH'
                    SQLITE_EXTENSION_VERSION="$("$NEXT_RUNTIME" -r 'echo SQLite3::version()["versionString"];')"
                    SQLITE_QUERY_VERSION="$("$NEXT_RUNTIME" -r 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();')"
                    "$NEXT_RUNTIME" -r '$extension = SQLite3::version()["versionString"]; $query = (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(); $fixed = in_array($extension, ["3.44.6", "3.50.7"], true) || version_compare($extension, "3.51.3", ">="); exit($extension === $query && $fixed ? 0 : 1);'
                    [ "$SQLITE_EXTENSION_VERSION" = "3.44.6" ]
                    [ "$SQLITE_QUERY_VERSION" = "3.44.6" ]
                    BASH
                : '';

            $versionBlocks[] = <<<BASH
                    # --- PHP {$minor} ---
                    ARCHIVE="{$archive}"
                    EXTRACT_DIRECTORY="{$extractDirectory}"
                    NEXT_RUNTIME="{$nextRuntime}"
                    mkdir -p "\$EXTRACT_DIRECTORY"
                    curl -fsSL {$this->curlRetryFlags()} "{$artifactUrl}" -o "\$ARCHIVE"
                    {$checksumVerification}
                    tar -xzf "\$ARCHIVE" -C "\$EXTRACT_DIRECTORY"
                    mv "\$EXTRACT_DIRECTORY/php" "\$NEXT_RUNTIME"
                    chmod +x "\$NEXT_RUNTIME"
                    PHP_VERSION="\$("\$NEXT_RUNTIME" -r 'echo PHP_VERSION;')"
                    [ "\$PHP_VERSION" = "{$patch}" ]
                    {$sqliteVerification}
                    sudo mkdir -p {$root}/{$minor}/bin
                    sudo mv -f "\$NEXT_RUNTIME" {$root}/{$minor}/bin/php
                    sudo chmod +x {$root}/{$minor}/bin/php
                    sudo ln -sf {$root}/{$minor}/bin/php /usr/local/bin/php{$minor}
                BASH;
        }

        $blocks = implode("\n", $versionBlocks);

        $header = $context === 'install'
            ? '#!/usr/bin/env bash'."\n".'# orbit install php-cli'."\n".'set -e'
            : '#!/usr/bin/env bash'."\n".'# orbit update php-cli'."\n".'set -e';

        return <<<BASH
            {$header}

            TEMP_DIR="\$(mktemp -d)"
            trap 'rm -rf "\$TEMP_DIR"' EXIT

            # Detect OS
            case "\$(uname -s)" in
                Linux)  OS=linux  ;;
                Darwin) OS=macos  ;;
                *)      echo "unsupported os" >&2; exit 1 ;;
            esac

            # Detect architecture
            case "\$(uname -m)" in
                x86_64|amd64)   ARCH=x86_64   ;;
                aarch64|arm64)  ARCH=aarch64  ;;
                *)              echo "unsupported arch" >&2; exit 1 ;;
            esac

            case "\${OS}-\${ARCH}" in
            {$this->php85ArtifactChecksumCases()}
                *) echo "unsupported PHP 8.5 artifact platform" >&2; exit 1 ;;
            esac

            {$blocks}

            # Set php8.5 as the default php
            sudo ln -sf {$root}/8.5/bin/php /usr/local/bin/php
            BASH;
    }

    private function curlRetryFlags(): string
    {
        return self::CurlRetryFlags;
    }

    private function php85ArtifactChecksumCases(): string
    {
        $cases = [];

        foreach (self::PHP_85_ARTIFACT_SHA256 as $platform => $sha256) {
            $cases[] = "    {$platform}) PHP_85_SHA256={$sha256} ;;";
        }

        return implode("\n", $cases);
    }
}
