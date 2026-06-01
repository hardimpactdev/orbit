<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\OrbitConfigStoreException;
use JsonException;

/**
 * Per-operator-host JSON-backed CLI configuration.
 *
 * Storage shape (schema_version 1):
 *
 * ```json
 * {
 *   "schema_version": 1,
 *   "active_gateway": "default",
 *   "gateways": {
 *     "default": {
 *       "url": "https://10.6.0.1",
 *       "wireguard_ip": "10.6.0.1",
 *       "ca_pem_path": "/home/orbit/.config/orbit/gateways/default/ca.pem",
 *       "ca_sha256": "sha256-hex",
 *       "ca_fingerprint": "sha256:fingerprint",
 *       "timeout": 30,
 *       "self_mode": "wireguard_https"
 *     }
 *   },
 *   "defaults": {"node": null, "profile": null},
 *   "meta": {"imported_from": null, "imported_at": null}
 * }
 * ```
 *
 * Owner model (D8): the file is owned by the invoking OS user. On nodes that means the `orbit`
 * system user; on developer Macs that means the operator's user. Multi-user nodes sharing one
 * Orbit install are out of scope.
 *
 * Precedence (D13): env vars override JSON values. The precedence chain lives inside
 * `GatewayApiServiceProvider`'s binding closure for `GatewayApiClient`, not inside
 * `apps/cli/config/orbit.php`. The config file stays env-only so it survives `config:cache`.
 *
 * `config:cache` interaction: cached config does not see edits to this file until the next
 * command run rebuilds the provider binding. This is acceptable because the closure is lazy.
 */
final readonly class OrbitConfigStore
{
    public const int CURRENT_SCHEMA_VERSION = 1;

    public const string DEFAULT_GATEWAY_NAME = 'default';

    public const string DEFAULT_SELF_MODE = 'wireguard_https';

    public const int DEFAULT_TIMEOUT_SECONDS = 30;

    public const int DIRECTORY_MODE = 0700;

    public const int FILE_MODE = 0600;

    public function __construct(
        private ?string $overridePath = null,
    ) {}

    public function path(): string
    {
        if ($this->overridePath !== null && $this->overridePath !== '') {
            return $this->overridePath;
        }

        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            throw new OrbitConfigStoreException('HOME environment variable is not set.', 'config_no_home');
        }

        return rtrim($home, '/').'/.config/orbit/config.json';
    }

    /**
     * Read the config file from disk. Returns the empty skeleton when the file does not exist.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return $this->emptySkeleton();
        }

        if (! is_readable($path)) {
            throw new OrbitConfigStoreException("Config file is not readable: {$path}", 'config_unreadable');
        }

        $this->assertOwnerAndPermissions($path);

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new OrbitConfigStoreException("Failed to read config file: {$path}", 'config_unreadable');
        }

        if (trim($contents) === '') {
            return $this->emptySkeleton();
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitConfigStoreException(
                "Config file contains invalid JSON: {$exception->getMessage()}",
                'config_invalid_json',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new OrbitConfigStoreException(
                'Config file root must be a JSON object.',
                'config_invalid_root',
            );
        }

        $schemaVersion = $decoded['schema_version'] ?? null;

        if (! is_int($schemaVersion)) {
            throw new OrbitConfigStoreException(
                'Config file is missing schema_version (integer).',
                'config_invalid_schema_version',
            );
        }

        if ($schemaVersion > self::CURRENT_SCHEMA_VERSION) {
            throw new OrbitConfigStoreException(
                "Config schema_version {$schemaVersion} is newer than this CLI supports (max ".self::CURRENT_SCHEMA_VERSION.').',
                'config_schema_version_too_new',
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Write the config file atomically. Creates parent directories with mode 0700 and the
     * file with mode 0600. Refuses to write when an existing file is owned by another user
     * (D8 owner-only model).
     *
     * @param  array<string, mixed>  $config
     */
    public function save(array $config): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            if (! @mkdir($directory, self::DIRECTORY_MODE, recursive: true) && ! is_dir($directory)) {
                throw new OrbitConfigStoreException("Failed to create config directory: {$directory}", 'config_mkdir_failed');
            }
        }

        @chmod($directory, self::DIRECTORY_MODE);

        if (is_file($path)) {
            $this->assertOwnerAndPermissions($path);
        }

        $config['schema_version'] = self::CURRENT_SCHEMA_VERSION;

        try {
            $encoded = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException $exception) {
            throw new OrbitConfigStoreException(
                "Failed to encode config: {$exception->getMessage()}",
                'config_encode_failed',
                previous: $exception,
            );
        }

        $tempPath = $path.'.tmp.'.bin2hex(random_bytes(8));

        if (file_put_contents($tempPath, $encoded, LOCK_EX) === false) {
            throw new OrbitConfigStoreException("Failed to write temp config: {$tempPath}", 'config_write_failed');
        }

        if (! @chmod($tempPath, self::FILE_MODE)) {
            @unlink($tempPath);
            throw new OrbitConfigStoreException("Failed to chmod temp config: {$tempPath}", 'config_chmod_failed');
        }

        if (! @rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new OrbitConfigStoreException("Failed to atomically rename config: {$path}", 'config_rename_failed');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeGateway(): ?array
    {
        $name = $this->activeGatewayName();

        if ($name === null) {
            return null;
        }

        return $this->gatewayEntry($name);
    }

    public function activeGatewayName(): ?string
    {
        $config = $this->read();
        $name = $config['active_gateway'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function gatewayEntries(): array
    {
        $config = $this->read();
        $gateways = $config['gateways'] ?? null;

        if (! is_array($gateways)) {
            return [];
        }

        $entries = [];

        foreach ($gateways as $name => $entry) {
            if (! is_string($name) || ! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $entries[$name] = $entry;
        }

        ksort($entries);

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function gatewayEntry(string $name): ?array
    {
        if (! self::isValidGatewayName($name)) {
            return null;
        }

        $entries = $this->gatewayEntries();

        return $entries[$name] ?? null;
    }

    public function setActiveGateway(string $name): bool
    {
        if (! self::isValidGatewayName($name)) {
            return false;
        }

        $config = $this->read();
        $gateways = $config['gateways'] ?? null;

        if (! is_array($gateways) || ! isset($gateways[$name]) || ! is_array($gateways[$name])) {
            return false;
        }

        $config['active_gateway'] = $name;
        $this->save($config);

        return true;
    }

    public static function isValidGatewayName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $name) === 1;
    }

    public function defaultNode(): ?string
    {
        $config = $this->read();
        $defaults = $config['defaults'] ?? null;

        if (! is_array($defaults)) {
            return null;
        }

        $node = $defaults['node'] ?? null;

        return is_string($node) && $node !== '' ? $node : null;
    }

    public function setDefaultNode(string $name): void
    {
        $config = $this->read();

        if (! is_array($config['defaults'] ?? null)) {
            $config['defaults'] = ['node' => null, 'profile' => null];
        }

        $config['defaults']['node'] = $name;

        $this->save($config);
    }

    public function clearDefaultNode(): bool
    {
        $config = $this->read();
        $defaults = $config['defaults'] ?? null;

        $wasSet = is_array($defaults)
            && is_string($defaults['node'] ?? null)
            && $defaults['node'] !== '';

        if (! is_array($config['defaults'] ?? null)) {
            $config['defaults'] = ['node' => null, 'profile' => null];
        }

        $config['defaults']['node'] = null;

        $this->save($config);

        return $wasSet;
    }

    /**
     * @return array<string, mixed>
     */
    public function emptySkeleton(): array
    {
        return [
            'schema_version' => self::CURRENT_SCHEMA_VERSION,
            'active_gateway' => null,
            'gateways' => [],
            'defaults' => ['node' => null, 'profile' => null],
            'meta' => ['imported_from' => null, 'imported_at' => null],
        ];
    }

    private function assertOwnerAndPermissions(string $path): void
    {
        $stat = @stat($path);

        if ($stat === false) {
            throw new OrbitConfigStoreException("Failed to stat config file: {$path}", 'config_stat_failed');
        }

        $ownerUid = $stat['uid'] ?? null;
        $processUid = function_exists('posix_geteuid') ? posix_geteuid() : null;

        if ($processUid !== null && $ownerUid !== null && $ownerUid !== $processUid) {
            throw new OrbitConfigStoreException(
                "Config file {$path} is owned by another user (refusing for safety).",
                'config_insecure_permissions',
            );
        }

        $mode = $stat['mode'] ?? null;

        if (! is_int($mode)) {
            return;
        }

        $perms = $mode & 0o777;

        if (($perms & 0o077) !== 0) {
            // Owner matches; silently tighten permissions.
            @chmod($path, self::FILE_MODE);
        }
    }
}
