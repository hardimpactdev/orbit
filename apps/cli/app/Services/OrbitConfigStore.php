<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\OrbitConfigStoreException;
use App\Services\Nodes\ManagedConfigAgentReadAclAssessor;
use Closure;
use JsonException;
use Symfony\Component\Process\Process;

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
 * Owner model (D8): the file is owned by the Orbit owner OS user. On nodes that means the
 * `orbit` system user; on developer Macs that means the operator's user. Consumer users may read
 * the owner config through a read-only ACL, but writes still require owner-user control.
 *
 * Agent-node durability: when the managed `agent` runtime user exists, atomic saves re-apply
 * the exact named ACLs after owner-only mode tighten — `u:agent:--x` on the config directory
 * (chmod 0700 otherwise zeroes the directory ACL mask so `test -e config.json` fails for agent)
 * and `u:agent:r--` on the config file after rename. Permission validation accepts the file's
 * narrow extended ACL when traditional group bits only reflect the ACL mask, and still
 * rejects ordinary group/other exposure. Do not broadly allow mode 0640.
 *
 * Precedence (D13): env vars override JSON values. The precedence chain lives inside
 * `GatewayApiServiceProvider`'s binding closure for `GatewayApiClient`, not inside
 * `apps/cli/config/orbit.php`. The config file stays env-only so it survives `config:cache`.
 *
 * `config:cache` interaction: cached config does not see edits to this file until the next
 * command run rebuilds the provider binding. This is acceptable because the closure is lazy.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class OrbitConfigStore
{
    public const int CURRENT_SCHEMA_VERSION = 1;

    public const string DEFAULT_GATEWAY_NAME = 'default';

    public const string DEFAULT_SELF_MODE = 'wireguard_https';

    public const int DEFAULT_TIMEOUT_SECONDS = 30;

    public const int DIRECTORY_MODE = 0700;

    public const int FILE_MODE = 0600;

    /** Exact LocalAgentAclEnsure directory traversal exception for config dirs. */
    public const string AGENT_CONFIG_DIRECTORY_ACL_SPEC = 'u:agent:--x';

    /** Exact LocalAgentAclEnsure config file read exception. */
    public const string AGENT_CONFIG_ACL_SPEC = 'u:agent:r--';

    /**
     * @param  (Closure(): bool)|null  $agentUserExists
     * @param  (Closure(list<string>, int): Process)|null  $processRunner
     */
    public function __construct(
        private ?string $overridePath = null,
        private ?Closure $agentUserExists = null,
        private ?Closure $processRunner = null,
        private ?ManagedConfigAgentReadAclAssessor $configAclAssessor = null,
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

        $this->assertOwnerAndPermissions($path, forWrite: false);

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
                "Config schema_version {$schemaVersion} is newer than this CLI supports (max "
                .self::CURRENT_SCHEMA_VERSION
                .').',
                'config_schema_version_too_new',
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Write the config file atomically. Creates parent directories with mode 0700 and the
     * file with mode 0600. Refuses to write when an existing file is owned by another user
     * (D8 owner-only model). On agent nodes, re-applies exact named agent ACLs after
     * owner-only directory chmod and file rename so routine config-writing commands do not
     * revoke agent directory traversal or config read.
     *
     * @param  array<string, mixed>  $config
     */
    public function save(array $config): void
    {
        $path = $this->path();
        $directory = dirname($path);
        $preserveAgentReadAcl = $this->shouldPreserveAgentConfigReadAcl($path);

        if (! is_dir($directory)) {
            if (! @mkdir($directory, self::DIRECTORY_MODE, recursive: true) && ! is_dir($directory)) {
                throw new OrbitConfigStoreException(
                    "Failed to create config directory: {$directory}",
                    'config_mkdir_failed',
                );
            }
        }

        @chmod($directory, self::DIRECTORY_MODE);

        // chmod 0700 zeroes the ACL mask; restore exact agent directory traversal.
        if ($preserveAgentReadAcl) {
            $this->applyAgentConfigDirectoryAcl($directory);
        }

        if (is_file($path)) {
            $this->assertOwnerAndPermissions($path, forWrite: true);
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

        if ($preserveAgentReadAcl) {
            $this->applyAgentConfigReadAcl($path);
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

        $wasSet = is_array($defaults) && is_string($defaults['node'] ?? null) && $defaults['node'] !== '';

        if (! is_array($config['defaults'] ?? null)) {
            $config['defaults'] = ['node' => null, 'profile' => null];
        }

        $config['defaults']['node'] = null;

        $this->save($config);

        return $wasSet;
    }

    /**
     * @return list<string>
     */
    public function enabledExtensions(): array
    {
        $config = $this->read();

        /** @var array<string, mixed>|null $extensions */
        $extensions = $config['extensions'] ?? null;

        if (! is_array($extensions)) {
            return [];
        }

        /** @var array<int, mixed>|null $enabled */
        $enabled = $extensions['enabled'] ?? null;

        if (! is_array($enabled)) {
            return [];
        }

        /** @var array<string, true> $slugs */
        $slugs = [];

        foreach ($enabled as $slug) {
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $slugs[$slug] = true;
        }

        $unique = array_keys($slugs);
        sort($unique);

        return $unique;
    }

    public function isExtensionEnabled(string $slug): bool
    {
        return in_array($slug, $this->enabledExtensions(), strict: true);
    }

    public function enableExtension(string $slug): void
    {
        $config = $this->read();
        $enabled = $this->enabledExtensions();

        if (! in_array($slug, $enabled, strict: true)) {
            $enabled[] = $slug;
            sort($enabled);
        }

        if (! is_array($config['extensions'] ?? null)) {
            $config['extensions'] = ['enabled' => []];
        }

        $config['extensions']['enabled'] = $enabled;

        $this->save($config);
    }

    public function disableExtension(string $slug): bool
    {
        $enabled = $this->enabledExtensions();

        if (! in_array($slug, $enabled, strict: true)) {
            return false;
        }

        $config = $this->read();

        if (! is_array($config['extensions'] ?? null)) {
            $config['extensions'] = ['enabled' => []];
        }

        $config['extensions']['enabled'] = array_values(array_filter(
            $enabled,
            static fn (string $enabledSlug): bool => $enabledSlug !== $slug,
        ));

        $this->save($config);

        return true;
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
            'extensions' => ['enabled' => []],
            'meta' => ['imported_from' => null, 'imported_at' => null],
        ];
    }

    private function assertOwnerAndPermissions(string $path, bool $forWrite): void
    {
        $stat = @stat($path);

        if ($stat === false) {
            throw new OrbitConfigStoreException("Failed to stat config file: {$path}", 'config_stat_failed');
        }

        $ownerUid = $stat['uid'] ?? null;
        $processUid = function_exists('posix_geteuid') ? posix_geteuid() : null;

        $mode = $stat['mode'] ?? null;
        $perms = is_int($mode) ? $mode & 0o777 : null;

        if ($processUid !== null && $ownerUid !== null && $ownerUid !== $processUid) {
            if (! $forWrite && ! is_writable($path) && ($perms === null || ($perms & 0o022) === 0)) {
                return;
            }

            throw new OrbitConfigStoreException(
                "Config file {$path} is owned by another user (refusing for safety).",
                'config_insecure_permissions',
            );
        }

        if (! is_int($mode) || $perms === null) {
            return;
        }

        // Other bits are never part of the allowed agent ACL contract.
        if (($perms & 0o007) !== 0) {
            $this->tightenOwnerOnlyMode($path);

            if ($this->shouldPreserveAgentConfigReadAcl($path)) {
                $this->applyAgentConfigReadAcl($path);
            }

            return;
        }

        if (($perms & 0o070) === 0) {
            return;
        }

        // Traditional group bits may only reflect the ACL mask for u:agent:r--.
        // Do not chmod that shape away (chmod zeros the mask → effective:none).
        if ($this->hasAllowedAgentConfigReadAcl($path)) {
            return;
        }

        // Ordinary group exposure without the narrow agent ACL: tighten owner-only.
        $this->tightenOwnerOnlyMode($path);
    }

    /**
     * @mago-expect lint:no-error-control-operator
     */
    private function tightenOwnerOnlyMode(string $path): void
    {
        // Non-fatal: a failed chmod is re-checked on the next read/write.
        @chmod($path, self::FILE_MODE);
    }

    private function shouldPreserveAgentConfigReadAcl(string $path): bool
    {
        if ($this->agentRuntimeUserExists()) {
            return true;
        }

        return is_file($path) && $this->hasAllowedAgentConfigReadAcl($path);
    }

    private function agentRuntimeUserExists(): bool
    {
        if ($this->agentUserExists instanceof Closure) {
            return ($this->agentUserExists)();
        }

        $probe = $this->runProcess(['id', '-u', ManagedConfigAgentReadAclAssessor::AGENT_USER], timeout: 5);

        return $probe->isSuccessful();
    }

    private function hasAllowedAgentConfigReadAcl(string $path): bool
    {
        $process = $this->runProcess(['getfacl', '-p', $path], timeout: 5);

        if (! $process->isSuccessful()) {
            return false;
        }

        return $this->configAclAssessor()->isManagedAgentReadOnly($process->getOutput());
    }

    private function applyAgentConfigDirectoryAcl(string $directory): void
    {
        $this->applyNamedAgentAcl(
            $directory,
            self::AGENT_CONFIG_DIRECTORY_ACL_SPEC,
            "Failed to re-apply agent directory ACL on config directory: {$directory}",
            'config_agent_directory_acl_failed',
        );
    }

    private function applyAgentConfigReadAcl(string $path): void
    {
        $this->applyNamedAgentAcl(
            $path,
            self::AGENT_CONFIG_ACL_SPEC,
            "Failed to re-apply agent read ACL on config file: {$path}",
            'config_agent_acl_failed',
        );
    }

    private function applyNamedAgentAcl(
        string $path,
        string $spec,
        string $failureMessage,
        string $errorCode,
    ): void {
        $process = $this->runProcess([
            'setfacl',
            '-m',
            $spec,
            $path,
        ], timeout: 10);

        if ($process->isSuccessful()) {
            return;
        }

        throw new OrbitConfigStoreException($failureMessage, $errorCode);
    }

    private function configAclAssessor(): ManagedConfigAgentReadAclAssessor
    {
        return $this->configAclAssessor ?? new ManagedConfigAgentReadAclAssessor;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, int $timeout): Process
    {
        if ($this->processRunner instanceof Closure) {
            return ($this->processRunner)($command, $timeout);
        }

        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
