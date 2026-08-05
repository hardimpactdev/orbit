<?php

declare(strict_types=1);

namespace Orbit\Core\Php;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class PhpCliArtifactCatalog
{
    public const string SCHEMA_VERSION = '1';

    public const string DEFAULT_CATALOG_RELATIVE_PATH = 'packages/core/resources/php-cli/artifact-catalog.json';

    public const string BUILD_CATALOG_RELATIVE_PATH = 'packages/core/resources/php-cli/artifact-catalog.build.json';

    public const string INSTALL_ROOT = '/opt/orbit/php';

    public const string INSTALL_CONTRACT_COMPATIBILITY = 'compatibility';

    public const string INSTALL_CONTRACT_MATRIX = 'matrix';

    /** @var list<string> */
    public const array SUPPORTED_MINORS = ['8.5', '8.4', '8.3'];

    public const string DEFAULT_MINOR = '8.5';

    /**
     * Fleet-scoped platforms that appear in the production matrix.
     *
     * Not a full OS/arch cross-product: Orbit publishes only the platforms
     * the real fleet needs (Ubuntu x86_64 app-dev/app-prod, macOS ARM app-dev).
     *
     * @var list<string>
     */
    public const array PLATFORMS = [
        'linux-x86_64',
        'macos-aarch64',
    ];

    /**
     * Sparse matrix cells per pinned patch: coverage on Linux x86_64 + macOS ARM,
     * standard on Linux x86_64 only (app-prod). No linux-aarch64, macos-x86_64,
     * or standard macOS artifacts.
     *
     * @var list<array{variant: string, platform: string}>
     */
    public const array MATRIX_CELLS = [
        ['variant' => 'coverage', 'platform' => 'linux-x86_64'],
        ['variant' => 'coverage', 'platform' => 'macos-aarch64'],
        ['variant' => 'standard', 'platform' => 'linux-x86_64'],
    ];

    public const int MATRIX_CELL_COUNT = 9;

    /**
     * @param  array<string, mixed>  $document
     */
    private function __construct(
        private array $document,
        private string $sourcePath,
    ) {}

    public static function load(?string $path = null): self
    {
        $resolved = $path ?? self::defaultPath();

        if (! is_file($resolved)) {
            throw new RuntimeException("php-cli artifact catalog is missing: {$resolved}");
        }

        $raw = file_get_contents($resolved);

        if ($raw === false) {
            throw new RuntimeException("Unable to read php-cli artifact catalog: {$resolved}");
        }

        try {
            $document = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("php-cli artifact catalog is not valid JSON: {$resolved}", 0, $exception);
        }

        if (! is_array($document)) {
            throw new RuntimeException("php-cli artifact catalog must be a JSON object: {$resolved}");
        }

        $catalog = new self(self::stringKeyedArray($document), $resolved);
        $catalog->assertValidDocument();

        return $catalog;
    }

    public static function loadBuild(?string $path = null): self
    {
        return self::load($path ?? self::defaultBuildPath());
    }

    public static function defaultPath(): string
    {
        $candidates = [
            dirname(__DIR__, 4).'/'.self::DEFAULT_CATALOG_RELATIVE_PATH,
            dirname(__DIR__, 2).'/resources/php-cli/artifact-catalog.json',
            getcwd().'/'.self::DEFAULT_CATALOG_RELATIVE_PATH,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    public static function defaultBuildPath(): string
    {
        $candidates = [
            dirname(__DIR__, 4).'/'.self::BUILD_CATALOG_RELATIVE_PATH,
            dirname(__DIR__, 2).'/resources/php-cli/artifact-catalog.build.json',
            getcwd().'/'.self::BUILD_CATALOG_RELATIVE_PATH,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function catalogRole(): string
    {
        $role = $this->document['catalog_role'] ?? 'runtime';

        if (! is_string($role) || ! in_array($role, ['runtime', 'build'], true)) {
            throw new RuntimeException(
                "php-cli artifact catalog_role must be 'runtime' or 'build'.",
            );
        }

        return $role;
    }

    public function installContract(): string
    {
        if ($this->catalogRole() === 'build') {
            return self::INSTALL_CONTRACT_MATRIX;
        }

        $contract = $this->document['install_contract'] ?? self::INSTALL_CONTRACT_COMPATIBILITY;

        if (
            ! is_string($contract)
            || ! in_array($contract, [self::INSTALL_CONTRACT_COMPATIBILITY, self::INSTALL_CONTRACT_MATRIX], true)
        ) {
            throw new RuntimeException(
                "php-cli install_contract must be 'compatibility' or 'matrix'.",
            );
        }

        return $contract;
    }

    public function usesCompatibilityContract(): bool
    {
        return $this->installContract() === self::INSTALL_CONTRACT_COMPATIBILITY;
    }

    public function usesMatrixContract(): bool
    {
        return $this->installContract() === self::INSTALL_CONTRACT_MATRIX;
    }

    public function artifactBaseUrl(): string
    {
        if ($this->usesCompatibilityContract()) {
            $base = $this->document['compatibility']['orbit_artifact_base_url'] ?? null;
        } elseif (isset($this->document['matrix']['artifact_base_url'])) {
            $base = $this->document['matrix']['artifact_base_url'];
        } else {
            $base = $this->document['artifact_base_url'] ?? null;
        }

        if (! is_string($base) || trim($base) === '') {
            throw new RuntimeException('php-cli artifact catalog is missing artifact base URL.');
        }

        return rtrim($base, '/');
    }

    public function bulkBaseUrl(): string
    {
        $base = $this->document['compatibility']['bulk_base_url'] ?? 'https://dl.static-php.dev/static-php-cli/bulk';

        if (! is_string($base) || trim($base) === '') {
            throw new RuntimeException('php-cli artifact catalog is missing bulk_base_url.');
        }

        return rtrim($base, '/');
    }

    /**
     * @return list<string>
     */
    public function orbitOwnedMinors(): array
    {
        if ($this->usesMatrixContract()) {
            return self::SUPPORTED_MINORS;
        }

        $minors = $this->document['compatibility']['orbit_owned_minors'] ?? ['8.5'];

        if (! is_array($minors)) {
            return ['8.5'];
        }

        return array_values(array_filter($minors, is_string(...)));
    }

    public function isOrbitOwnedMinor(string $minor): bool
    {
        return in_array($minor, $this->orbitOwnedMinors(), true);
    }

    public function staticPhpCliVersion(): string
    {
        return $this->stringField('static_php_cli_version');
    }

    public function staticPhpCliExtJsonSha256(): string
    {
        return $this->stringField('static_php_cli_ext_json_sha256');
    }

    /**
     * Official static-php-cli config/source.json digest for the pinned SPC tag.
     * Coverage builds patch pcov.path after verifying this identity.
     */
    public function staticPhpCliSourceJsonSha256(): string
    {
        return $this->stringField('static_php_cli_source_json_sha256');
    }

    public function sqliteVersion(): string
    {
        return $this->stringField('sqlite_version');
    }

    public function sqliteSourceId(): string
    {
        return $this->stringField('sqlite_source_id');
    }

    public function sqliteArchiveSha256(): string
    {
        return $this->stringField('sqlite_archive_sha256');
    }

    /**
     * Pinned PCOV source for coverage builds (never the moving pecl.php.net/get/pcov URL).
     *
     * @return array{
     *     version: string,
     *     url: string,
     *     filename: string,
     *     archive_sha256: string,
     *     spc_source_name: string,
     *     config_m4_php_version_patch?: string,
     *     config_m4_php_version_patch_sha256?: string
     * }
     */
    public function pcovPin(): array
    {
        $pcov = $this->document['pcov'] ?? null;

        if (! is_array($pcov)) {
            throw new RuntimeException('php-cli artifact catalog is missing the pcov pin.');
        }

        foreach (['version', 'url', 'filename', 'archive_sha256', 'spc_source_name'] as $key) {
            if (! is_string($pcov[$key] ?? null) || $pcov[$key] === '') {
                throw new RuntimeException("php-cli artifact catalog pcov.{$key} is required.");
            }
        }

        $archiveSha256 = $pcov['archive_sha256'];
        if (! is_string($archiveSha256) || ! preg_match('/^[a-f0-9]{64}$/', $archiveSha256)) {
            throw new RuntimeException('php-cli artifact catalog pcov.archive_sha256 must be a 64-char hex digest.');
        }

        $patchSha = $pcov['config_m4_php_version_patch_sha256'] ?? null;

        if ($patchSha !== null) {
            if (! is_string($patchSha) || ! preg_match('/^[a-f0-9]{64}$/', $patchSha)) {
                throw new RuntimeException(
                    'php-cli artifact catalog pcov.config_m4_php_version_patch_sha256 must be a 64-char hex digest.',
                );
            }
        }

        /** @var array{version: string, url: string, filename: string, archive_sha256: string, spc_source_name: string, config_m4_php_version_patch?: string, config_m4_php_version_patch_sha256?: string} $pcov */
        return $pcov;
    }

    public function pcovVersion(): string
    {
        return $this->pcovPin()['version'];
    }

    public function pcovUrl(): string
    {
        return $this->pcovPin()['url'];
    }

    public function pcovArchiveSha256(): string
    {
        return $this->pcovPin()['archive_sha256'];
    }

    /**
     * @return array<string, string>
     */
    public function patchPins(): array
    {
        $pins = $this->document['patch_pins'] ?? null;

        if (! is_array($pins)) {
            throw new RuntimeException('php-cli artifact catalog is missing patch_pins.');
        }

        $normalized = [];

        foreach (self::SUPPORTED_MINORS as $minor) {
            $patch = $pins[$minor] ?? null;

            if (! is_string($patch) || $patch === '') {
                throw new RuntimeException("php-cli artifact catalog is missing patch pin for {$minor}.");
            }

            $normalized[$minor] = $patch;
        }

        return $normalized;
    }

    public function patchForMinor(string $minor): string
    {
        $pins = $this->patchPins();

        if (! array_key_exists($minor, $pins)) {
            throw new InvalidArgumentException("Unsupported PHP minor '{$minor}'.");
        }

        return $pins[$minor];
    }

    public function phpSourceSha256(string $patch): string
    {
        $map = $this->document['php_source_sha256'] ?? null;

        if (! is_array($map) || ! is_string($map[$patch] ?? null) || $map[$patch] === '') {
            throw new InvalidArgumentException("Missing PHP source sha256 for {$patch}.");
        }

        return $map[$patch];
    }

    public function spcArchiveSha256(string $platform): string
    {
        $map = $this->document['spc_archive_sha256'] ?? null;

        if (! is_array($map) || ! is_string($map[$platform] ?? null) || $map[$platform] === '') {
            throw new InvalidArgumentException("Missing SPC archive sha256 for {$platform}.");
        }

        return $map[$platform];
    }

    /**
     * @return list<string>
     */
    public function platforms(): array
    {
        return self::PLATFORMS;
    }

    /**
     * @return list<string>
     */
    public function variants(): array
    {
        return PhpCliVariant::values();
    }

    /**
     * @return list<string>
     */
    public function baseExtensions(): array
    {
        $extensions = $this->document['extensions']['base'] ?? null;

        if (! is_array($extensions)) {
            throw new RuntimeException('php-cli artifact catalog is missing base extensions.');
        }

        return array_values(array_filter($extensions, is_string(...)));
    }

    /**
     * @return list<string>
     */
    public function extensionsFor(PhpCliVariant $variant): array
    {
        $extensions = $this->baseExtensions();

        if ($variant === PhpCliVariant::Coverage) {
            $extra = $this->document['extensions']['coverage_extra'] ?? [];

            if (! is_array($extra)) {
                throw new RuntimeException('php-cli artifact catalog is missing coverage_extra extensions.');
            }

            foreach ($extra as $extension) {
                if (! (is_string($extension) && $extension !== '' && ! in_array($extension, $extensions, true))) {
                    continue;
                }

                $extensions[] = $extension;
            }
        }

        return $extensions;
    }

    public function artifactFileName(string $patch, PhpCliVariant $variant, string $platform): string
    {
        $this->assertPlatform($platform);

        if ($this->usesCompatibilityContract()) {
            return "php-{$patch}-cli-{$platform}.tar.gz";
        }

        return "php-{$patch}-cli-{$variant->value}-{$platform}.tar.gz";
    }

    public function artifactUrl(string $patch, PhpCliVariant $variant, string $platform, ?string $minor = null): string
    {
        $this->assertPlatform($platform);
        $minor ??= $this->minorForPatch($patch);

        if ($this->usesCompatibilityContract()) {
            if ($this->isOrbitOwnedMinor($minor)) {
                return $this->artifactBaseUrl().'/'.$this->artifactFileName($patch, $variant, $platform);
            }

            return $this->bulkBaseUrl().'/'.$this->artifactFileName($patch, $variant, $platform);
        }

        return $this->artifactBaseUrl().'/'.$this->artifactFileName($patch, $variant, $platform);
    }

    public function artifactSha256(string $patch, PhpCliVariant $variant, string $platform): ?string
    {
        $this->assertPlatform($platform);

        if ($this->usesCompatibilityContract()) {
            $minor = $this->minorForPatch($patch);

            if (! $this->isOrbitOwnedMinor($minor)) {
                // Bulk static-php.dev artifacts are not checksum-pinned historically.
                return null;
            }

            $sha = $this->document['compatibility']['published_checksums'][$patch][$platform] ?? null;

            if ($sha === null) {
                return null;
            }

            if (! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/', $sha)) {
                throw new RuntimeException("Invalid compatibility sha256 for {$patch}/{$platform}.");
            }

            return $sha;
        }

        $artifacts = $this->matrixArtifacts();
        $sha = $artifacts[$patch][$variant->value][$platform] ?? null;

        if ($sha === null) {
            return null;
        }

        if (! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new RuntimeException(
                "Invalid sha256 for php-cli artifact {$patch}/{$variant->value}/{$platform}.",
            );
        }

        return $sha;
    }

    public function requiresChecksum(string $minor): bool
    {
        if ($this->usesMatrixContract()) {
            return true;
        }

        return $this->isOrbitOwnedMinor($minor);
    }

    public function requiresSqliteSafetyCheck(string $minor): bool
    {
        return $this->isOrbitOwnedMinor($minor) || $this->usesMatrixContract();
    }

    /**
     * In compatibility mode, install scripts skip PCOV checks because the retained
     * published binaries do not include PCOV. Doctor classifies against the effective
     * standard compatibility runtime so coverage desire alone is not permanent drift.
     */
    public function installVerifiesPcov(): bool
    {
        return $this->usesMatrixContract();
    }

    public function isPublished(string $patch, PhpCliVariant $variant, string $platform): bool
    {
        return $this->artifactSha256($patch, $variant, $platform) !== null;
    }

    public function publicationStatus(): string
    {
        $status = $this->document['publication']['status'] ?? 'unpublished';

        return is_string($status) && $status !== '' ? $status : 'unpublished';
    }

    /**
     * @return list<array{
     *     minor: string,
     *     patch: string,
     *     variant: string,
     *     platform: string,
     *     filename: string,
     *     url: string,
     *     sha256: string|null,
     *     published: bool
     * }>
     */
    public function matrix(): array
    {
        $rows = [];

        foreach ($this->patchPins() as $minor => $patch) {
            foreach (self::MATRIX_CELLS as $cell) {
                $variant = PhpCliVariant::from($cell['variant']);
                $platform = $cell['platform'];
                $sha = $this->matrixArtifactSha256($patch, $variant, $platform);
                $rows[] = [
                    'minor' => $minor,
                    'patch' => $patch,
                    'variant' => $variant->value,
                    'platform' => $platform,
                    'filename' => "php-{$patch}-cli-{$variant->value}-{$platform}.tar.gz",
                    'url' =>
                        $this->matrixArtifactBaseUrl().'/php-'.$patch.'-cli-'.$variant->value.'-'.$platform.'.tar.gz',
                    'sha256' => $sha,
                    'published' => $sha !== null,
                ];
            }
        }

        return $rows;
    }

    public function matrixFullyPublished(): bool
    {
        return array_all($this->matrix(), static fn ($row) => $row['published']);
    }

    /**
     * @return array<string, string>
     */
    public function publishedChecksumsFor(PhpCliVariant $variant, string $platform): array
    {
        $this->assertPlatform($platform);
        $checksums = [];

        foreach ($this->patchPins() as $minor => $patch) {
            $sha = $this->artifactSha256($patch, $variant, $platform);

            if ($sha === null && $this->requiresChecksum($minor)) {
                throw new RuntimeException(
                    "php-cli {$variant->value} artifact for PHP {$patch} on {$platform} is unpublished.",
                );
            }

            if ($sha !== null) {
                $checksums[$minor] = $sha;
            }
        }

        return $checksums;
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->document;
    }

    private function minorForPatch(string $patch): string
    {
        foreach ($this->patchPins() as $minor => $pinned) {
            if ($pinned === $patch) {
                return $minor;
            }
        }

        throw new InvalidArgumentException("Unknown PHP patch '{$patch}'.");
    }

    /**
     * @return array<string, mixed>
     */
    private function matrixArtifacts(): array
    {
        if (isset($this->document['matrix']['artifacts']) && is_array($this->document['matrix']['artifacts'])) {
            return self::stringKeyedArray($this->document['matrix']['artifacts']);
        }

        if (isset($this->document['artifacts']) && is_array($this->document['artifacts'])) {
            return self::stringKeyedArray($this->document['artifacts']);
        }

        throw new RuntimeException('php-cli artifact catalog is missing matrix artifacts.');
    }

    private function matrixArtifactBaseUrl(): string
    {
        $base = $this->document['matrix']['artifact_base_url'] ?? $this->document['artifact_base_url'] ?? null;

        if (! is_string($base) || trim($base) === '') {
            throw new RuntimeException('php-cli matrix artifact_base_url is missing.');
        }

        return rtrim($base, '/');
    }

    private function matrixArtifactSha256(string $patch, PhpCliVariant $variant, string $platform): ?string
    {
        $artifacts = $this->matrixArtifacts();
        $sha = $artifacts[$patch][$variant->value][$platform] ?? null;

        if ($sha === null) {
            return null;
        }

        if (! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new RuntimeException(
                "Invalid matrix sha256 for php-cli artifact {$patch}/{$variant->value}/{$platform}.",
            );
        }

        return $sha;
    }

    /**
     * Require every fleet-scoped sparse matrix slot (patch → variant → platform)
     * from MATRIX_CELLS to exist. Reject extra non-fleet platform keys so the
     * catalog cannot silently re-grow a full OS/arch cross-product.
     * Values may be null (unpublished build slots) or a 64-char sha256.
     */
    private function assertMatrixSlotKeysExist(): void
    {
        $artifacts = $this->matrixArtifacts();
        $requiredByVariant = [];

        foreach (self::MATRIX_CELLS as $cell) {
            $requiredByVariant[$cell['variant']][] = $cell['platform'];
        }

        foreach ($this->patchPins() as $patch) {
            if (! array_key_exists($patch, $artifacts) || ! is_array($artifacts[$patch])) {
                throw new RuntimeException("php-cli matrix is missing patch slot '{$patch}'.");
            }

            foreach ($requiredByVariant as $variantValue => $platforms) {
                if (
                    ! array_key_exists($variantValue, $artifacts[$patch])
                    || ! is_array($artifacts[$patch][$variantValue])
                ) {
                    throw new RuntimeException(
                        "php-cli matrix is missing variant slot '{$patch}/{$variantValue}'.",
                    );
                }

                foreach ($platforms as $platform) {
                    if (! array_key_exists($platform, $artifacts[$patch][$variantValue])) {
                        throw new RuntimeException(
                            "php-cli matrix is missing platform slot '{$patch}/{$variantValue}/{$platform}'.",
                        );
                    }

                    $sha = $artifacts[$patch][$variantValue][$platform];

                    if ($sha !== null && (! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/', $sha))) {
                        throw new RuntimeException(
                            "php-cli matrix slot '{$patch}/{$variantValue}/{$platform}' must be null or a sha256 digest.",
                        );
                    }
                }

                foreach (array_keys($artifacts[$patch][$variantValue]) as $extraPlatform) {
                    if (! is_string($extraPlatform)) {
                        continue;
                    }

                    if (! in_array($extraPlatform, $platforms, true)) {
                        throw new RuntimeException(
                            "php-cli matrix has non-fleet platform slot '{$patch}/{$variantValue}/{$extraPlatform}'.",
                        );
                    }
                }
            }

            foreach (array_keys($artifacts[$patch]) as $extraVariant) {
                if (! is_string($extraVariant)) {
                    continue;
                }

                if (! array_key_exists($extraVariant, $requiredByVariant)) {
                    throw new RuntimeException(
                        "php-cli matrix has unexpected variant slot '{$patch}/{$extraVariant}'.",
                    );
                }
            }
        }
    }

    private function assertValidDocument(): void
    {
        if (($this->document['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('php-cli artifact catalog schema_version must be 1.');
        }

        if (($this->document['tool'] ?? null) !== 'php-cli') {
            throw new RuntimeException('php-cli artifact catalog tool must be php-cli.');
        }

        // Reject unknown roles/contracts early (including via accessors).
        $this->catalogRole();
        $this->installContract();
        $this->patchPins();
        $this->baseExtensions();
        $this->pcovPin();
        $this->staticPhpCliExtJsonSha256();
        $this->staticPhpCliSourceJsonSha256();
        $this->assertMatrixSlotKeysExist();

        if ($this->catalogRole() === 'build') {
            $this->matrixArtifactBaseUrl();

            return;
        }

        if ($this->usesCompatibilityContract()) {
            $this->artifactBaseUrl();
            $this->bulkBaseUrl();

            $checksums = $this->document['compatibility']['published_checksums'] ?? null;

            if (! is_array($checksums) || $checksums === []) {
                throw new RuntimeException(
                    'compatibility catalog requires published_checksums for Orbit-owned minors.',
                );
            }

            return;
        }

        if ($this->usesMatrixContract() && ! $this->matrixFullyPublished()) {
            throw new RuntimeException(
                'Runtime matrix install_contract requires a fully published fleet-scoped 9-cell matrix; use compatibility until cutover.',
            );
        }
    }

    private function assertPlatform(string $platform): void
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException("Unsupported php-cli platform '{$platform}'.");
        }
    }

    private function stringField(string $key): string
    {
        $value = $this->document[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("php-cli artifact catalog is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $input): array
    {
        $normalized = [];

        foreach ($input as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
