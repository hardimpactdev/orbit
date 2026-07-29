<?php

declare(strict_types=1);

namespace App\Tools;

use Orbit\Core\Php\PhpCliArtifactCatalog;
use Orbit\Core\Php\PhpCliVariant;
use RuntimeException;

final class PhpCliTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux', 'macos'];

    private const string CurlRetryFlags = '--retry 5 --retry-delay 2 --retry-all-errors';

    public const string INSTALL_ROOT = PhpCliArtifactCatalog::INSTALL_ROOT;

    public const string BULK_BASE_URL = 'https://dl.static-php.dev/static-php-cli/bulk';

    public const string ORBIT_ARTIFACT_BASE_URL = 'https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6';

    /**
     * @var array<string, string>
     */
    public const array PATCH_PINS = [
        '8.5' => '8.5.8',
        '8.4' => '8.4.21',
        '8.3' => '8.3.31',
    ];

    public function __construct(
        private readonly ?PhpCliArtifactCatalog $catalog = null,
    ) {}

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

    /**
     * Default minor patch pin used as the bulk updateAll version marker.
     * Install/update always re-apply every supported minor from the catalog.
     */
    #[\Override]
    public function latestSupportedVersion(): string
    {
        $catalog = $this->catalog ?? PhpCliArtifactCatalog::load();

        return $catalog->patchForMinor(PhpCliArtifactCatalog::DEFAULT_MINOR);
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return $this->buildScript('install', $this->normalizeConfig($config));
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return $this->buildScript('update', $this->normalizeConfig($config));
    }

    #[\Override]
    public function probeMetadata(): array
    {
        $root = self::INSTALL_ROOT;

        return [
            'binary' => "{$root}/8.5/bin/php",
            'version_command' => "{$root}/8.5/bin/php --version",
            'probe' => 'php_cli_runtimes',
        ];
    }

    /**
     * Resolve the effective install variant.
     *
     * Role ownership is authoritative: app-prod always standard, app-dev always
     * coverage. Explicit or stored values cannot place PCOV on app-prod or strip
     * coverage from app-dev.
     *
     * @param  array<string, mixed>  $config
     */
    public function resolveVariant(array $config = [], ?string $role = null): PhpCliVariant
    {
        if (is_string($role) && $role !== '') {
            return PhpCliVariant::forRole($role);
        }

        if (array_key_exists('variant', $config)) {
            return PhpCliVariant::fromMixed($config['variant']);
        }

        return PhpCliVariant::Coverage;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function assertVariantAllowedForRole(array $config, ?string $role): void
    {
        if ($role === null || $role === '' || ! array_key_exists('variant', $config)) {
            return;
        }

        $requested = PhpCliVariant::fromMixed($config['variant']);
        $required = PhpCliVariant::forRole($role);

        if ($requested !== $required) {
            throw new RuntimeException(
                "php-cli variant '{$requested->value}' is not allowed on role '{$role}'; required '{$required->value}'.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function buildScript(string $context, array $config = []): string
    {
        $variant = $this->resolveVariant($config);
        $catalog = $this->catalog();
        $root = self::INSTALL_ROOT;
        $sqliteVersion = $catalog->sqliteVersion();
        $verifyPcov = $catalog->installVerifiesPcov();

        $stageBlocks = [];
        $installBlocks = [];

        foreach ($catalog->patchPins() as $minor => $patch) {
            $archive = "\${TEMP_DIR}/php-{$minor}.tar.gz";
            $extractDirectory = "\${TEMP_DIR}/php-{$minor}";
            $nextRuntime = "\${TEMP_DIR}/php-{$minor}.next";
            if ($catalog->usesCompatibilityContract()) {
                $filenameTemplate = "php-{$patch}-cli-\${OS}-\${ARCH}.tar.gz";
            } else {
                $filenameTemplate = "php-{$patch}-cli-{$variant->value}-\${OS}-\${ARCH}.tar.gz";
            }

            if ($catalog->usesCompatibilityContract() && ! $catalog->isOrbitOwnedMinor($minor)) {
                $artifactUrl = $catalog->bulkBaseUrl().'/${ARTIFACT_NAME}';
            } else {
                $artifactUrl = $catalog->artifactBaseUrl().'/${ARTIFACT_NAME}';
            }

            $checksumBlock = $this->checksumStageBlock($catalog, $variant, $minor, $patch);
            $sqliteBlock = $catalog->requiresSqliteSafetyCheck($minor)
                ? <<<BASH
                    SQLITE_EXTENSION_VERSION="\$("\$NEXT_RUNTIME" -r 'echo SQLite3::version()["versionString"];')"
                    SQLITE_QUERY_VERSION="\$("\$NEXT_RUNTIME" -r 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();')"
                    "\$NEXT_RUNTIME" -r '\$extension = SQLite3::version()["versionString"]; \$query = (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(); \$fixed = in_array(\$extension, ["{$sqliteVersion}", "3.50.7"], true) || version_compare(\$extension, "3.51.3", ">="); exit(\$extension === \$query && \$fixed ? 0 : 1);'
                    [ "\$SQLITE_EXTENSION_VERSION" = "{$sqliteVersion}" ]
                    [ "\$SQLITE_QUERY_VERSION" = "{$sqliteVersion}" ]
                    BASH
                : '';

            $pcovBlock = '';
            if ($verifyPcov) {
                $pcovBlock = $variant === PhpCliVariant::Coverage
                    ? <<<'BASH'
                        "$NEXT_RUNTIME" -r 'exit(extension_loaded("pcov") ? 0 : 1);'
                        "$NEXT_RUNTIME" -r 'exit(function_exists("pcov\\start") ? 0 : 1);'
                        PCOV_ENABLED="$("$NEXT_RUNTIME" -r 'echo (string) (int) (bool) ini_get("pcov.enabled");')"
                        [ "$PCOV_ENABLED" = "1" ]
                        "$NEXT_RUNTIME" --ri pcov >/dev/null
                        BASH
                    : <<<'BASH'
                        "$NEXT_RUNTIME" -r 'exit(extension_loaded("pcov") ? 1 : 0);'
                        if "$NEXT_RUNTIME" --ri pcov >/dev/null 2>&1; then
                            echo "standard php-cli must not expose pcov" >&2
                            exit 1
                        fi
                        BASH;
            }

            $stageBlocks[] = <<<BASH
                    # --- stage PHP {$minor} ({$variant->value}, contract={$catalog->installContract()}) ---
                    ARCHIVE="{$archive}"
                    EXTRACT_DIRECTORY="{$extractDirectory}"
                    NEXT_RUNTIME="{$nextRuntime}"
                    ARTIFACT_NAME="{$filenameTemplate}"
                    ARTIFACT_URL="{$artifactUrl}"
                    ARTIFACT_SHA256=
                    {$checksumBlock}
                    mkdir -p "\$EXTRACT_DIRECTORY"
                    curl -fsSL {$this->curlRetryFlags()} "\$ARTIFACT_URL" -o "\$ARCHIVE"
                    if [ -n "\$ARTIFACT_SHA256" ]; then
                        printf '%s  %s\\n' "\$ARTIFACT_SHA256" "\$ARCHIVE" | shasum -a 256 -c -
                    fi
                    tar -xzf "\$ARCHIVE" -C "\$EXTRACT_DIRECTORY"
                    mv "\$EXTRACT_DIRECTORY/php" "\$NEXT_RUNTIME"
                    chmod +x "\$NEXT_RUNTIME"
                    PHP_VERSION="\$("\$NEXT_RUNTIME" -r 'echo PHP_VERSION;')"
                    [ "\$PHP_VERSION" = "{$patch}" ]
                    {$sqliteBlock}
                    {$pcovBlock}
                BASH;

            $installBlocks[] = <<<BASH
                    # --- install PHP {$minor} ---
                    NEXT_RUNTIME="{$nextRuntime}"
                    sudo mkdir -p {$root}/{$minor}/bin
                    sudo mv -f "\$NEXT_RUNTIME" {$root}/{$minor}/bin/php
                    sudo chmod +x {$root}/{$minor}/bin/php
                    sudo ln -sf {$root}/{$minor}/bin/php /usr/local/bin/php{$minor}
                BASH;
        }

        $stages = implode("\n", $stageBlocks);
        $installs = implode("\n", $installBlocks);

        $header = $context === 'install'
            ? '#!/usr/bin/env bash'."\n".'# orbit install php-cli'."\n".'set -euo pipefail'
            : '#!/usr/bin/env bash'."\n".'# orbit update php-cli'."\n".'set -euo pipefail';

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

            # Stage and verify every minor before replacing any installed runtime.
            {$stages}

            # Atomic install + relink after all staged runtimes passed verification.
            {$installs}

            # Set php8.5 as the default php
            sudo ln -sf {$root}/8.5/bin/php /usr/local/bin/php
            BASH;
    }

    /**
     * Probe script that classifies every supported minor for doctor.
     */
    public function runtimeProbeScript(PhpCliVariant $variant): string
    {
        $catalog = $this->catalog();
        $root = self::INSTALL_ROOT;
        $lines = [];

        foreach ($catalog->patchPins() as $minor => $patch) {
            $binary = "{$root}/{$minor}/bin/php";
            $lines[] = <<<BASH
                probe_minor "{$minor}" "{$patch}" "{$binary}"
                BASH;
        }

        $probes = implode("\n", $lines);

        return <<<BASH
            set -eu
            expected_variant='{$variant->value}'

            probe_minor() {
                minor="\$1"
                expected_patch="\$2"
                binary="\$3"
                present=0
                patch=''
                extension_loaded_pcov=0
                function_exists_pcov_start=0
                pcov_enabled=0
                ri_pcov_ok=0

                if [ -x "\$binary" ]; then
                    present=1
                    patch="\$("\$binary" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
                    if "\$binary" -r 'exit(extension_loaded("pcov") ? 0 : 1);' >/dev/null 2>&1; then
                        extension_loaded_pcov=1
                    fi
                    if "\$binary" -r 'exit(function_exists("pcov\\\\start") ? 0 : 1);' >/dev/null 2>&1; then
                        function_exists_pcov_start=1
                    fi
                    if [ "\$("\$binary" -r 'echo (string) (int) (bool) ini_get("pcov.enabled");' 2>/dev/null || echo 0)" = 1 ]; then
                        pcov_enabled=1
                    fi
                    if "\$binary" --ri pcov >/dev/null 2>&1; then
                        ri_pcov_ok=1
                    fi
                fi

                printf '%s\n' "\$minor|\$expected_patch|\$present|\$patch|\$extension_loaded_pcov|\$function_exists_pcov_start|\$pcov_enabled|\$ri_pcov_ok"
            }

            {$probes}
            BASH;
    }

    /**
     * @return array<string, string>
     */
    public function patchPins(): array
    {
        return $this->catalog()->patchPins();
    }

    public function catalog(): PhpCliArtifactCatalog
    {
        return $this->catalog ?? PhpCliArtifactCatalog::load();
    }

    private function curlRetryFlags(): string
    {
        return self::CurlRetryFlags;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $config): array
    {
        $normalized = [];

        foreach ($config as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    private function checksumStageBlock(
        PhpCliArtifactCatalog $catalog,
        PhpCliVariant $variant,
        string $minor,
        string $patch,
    ): string {
        if (! $catalog->requiresChecksum($minor)) {
            return '# bulk artifact: no pinned checksum in compatibility contract';
        }

        $cases = [];

        foreach ($catalog->platforms() as $platform) {
            $sha = $catalog->artifactSha256($patch, $variant, $platform);

            if ($sha === null) {
                continue;
            }

            $cases[] = "    {$platform}) ARTIFACT_SHA256={$sha} ;;";
        }

        if ($cases === []) {
            return <<<'BASH'
                echo "unsupported php-cli platform or unpublished artifact for this minor" >&2
                exit 1
                BASH;
        }

        $caseBody = implode("\n", $cases);

        return <<<BASH
            case "\${OS}-\${ARCH}" in
            {$caseBody}
                *) echo "unsupported php-cli platform or unpublished artifact for {$patch}" >&2; exit 1 ;;
            esac
            [ -n "\$ARTIFACT_SHA256" ] || { echo "php-cli artifact checksum missing for {$patch}/\${OS}-\${ARCH}" >&2; exit 1; }
            BASH;
    }
}
