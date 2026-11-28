<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Engine\EngineUnsupported;

/**
 * The inputs of the host catalogue primitives (docs/reference/executor.md#the-host-family) for a Route's files on one Node.
 * The Gateway renders every file whole and sends each path, owner, mode, unit, lock and the live digest a `file.read` step
 * returned under the Node publication hold;
 * the executor holds no Route path. Each builder answers one order's input, derived from the admitted plan alone, so a resend
 * of a step carries the same bytes (config.apply's identity is the SHA-256 of its input).
 */
final readonly class RouteHostSteps
{
    /** Swamp's publication lock for the Caddyfile and the PHP-FPM pools (`orbit_route_publish`, `route.withdraw_site`). */
    public const string CaddyLock = '/etc/caddy/.orbit-publish.lock';

    /** The Node's private DNS lock (`DnsmasqPrivateDnsManager`, `dns.publish_records`). */
    public const string DnsLock = '/run/lock/orbit-dnsmasq.lock';

    /**
     * config.apply's recovery folders lie beside the executor's blob cache, outside every file a Route manages. Each config.apply
     * step has its own subfolder (`pool-install`, `pool-reload`, `caddy`, `dns`), so a pending record blocks only a replay of
     * that same order, never another step's.
     */
    public const string RecoveryDir = '/var/lib/orbit/executor/config-recovery';

    public const string Caddyfile = '/etc/caddy/Caddyfile';

    public const string Records = '/etc/dnsmasq.d/orbit-records.conf';

    public const string Catalog = '/var/lib/orbit/private-dns/catalog.json';

    /**
     * The one trust anchor of the Orbit root, as `route.publish_site` and Swamp's site phase place it: either publisher replaces
     * it, and the plain update then drops a retired root. Other roots in the store are left as they are.
     */
    public const string TrustName = 'orbit-managed-root-ca.crt';

    public const string TrustFile = '/usr/local/share/ca-certificates/'.self::TrustName;

    /** @return array{state: string, path: string} */
    public static function absent(string $path): array
    {
        return ['state' => 'absent', 'path' => $path];
    }

    /** Whether a recorded `file.read` found a link at the path: the write removes it first, never following it. */
    public static function linked(?string $read): bool
    {
        $answer = is_string($read) ? json_decode($read, true) : null;

        return is_array($answer) && ($answer['state'] ?? null) === 'present' && ($answer['type'] ?? null) === 'symlink';
    }

    /** The configuration dnsmasq.service loads: Debian's unit reads the folder without its package leftovers. */
    private const array DnsmasqSkip = ['.dpkg-dist', '.dpkg-old', '.dpkg-new'];

    /**
     * A config.apply input: whole files under one lock, their validators, and the units to load on every run.
     *
     * @param  list<array<string, mixed>>  $files
     * @param  list<array<string, mixed>>  $validators
     * @param  list<array{unit: string, load: string}>  $units
     * @return array<string, mixed>
     */
    public static function apply(string $lock, string $step, array $files, array $validators, array $units = []): array
    {
        return ['lock' => $lock, 'recovery_dir' => self::RecoveryDir.'/'.$step, 'files' => $files, 'validators' => $validators, 'units' => $units];
    }

    /**
     * One config.apply file. `$from` is the digest of the bytes it was rendered from; without one the live file must be absent
     * or already hold the content.
     *
     * @return array<string, mixed>
     */
    public static function file(string $path, string $content, ?string $from, string $user, string $group, string $mode): array
    {
        return ['path' => $path, 'content' => ['blob' => ['content' => $content]], ...($from === null ? [] : ['from_sha256' => $from]),
            'owner' => ['user' => $user, 'group' => $group, 'mode' => $mode]];
    }

    /**
     * The shared or production pools of one Node, rendered whole: the account's group and home replace the placeholders the
     * shared pool render leaves (SharedPhpPoolInput), as Swamp's site phase does.
     *
     * @param  list<array{version: string, user: ?string, content: array{blob: array{content: string}}}>  $pools  RouteEngineFlow::pools
     * @param  callable(int): ?string  $from  The live digest of the pool at an index, from its read (`read-pool-<k>`); null when absent.
     * @return array{files: list<array<string, mixed>>, validators: list<array<string, mixed>>, units: list<string>}
     */
    public static function pools(array $pools, string $group, string $home, callable $from): array
    {
        $files = $validators = $units = [];
        foreach ($pools as $index => $pool) {
            ['version' => $version, 'user' => $user] = $pool;
            $content = self::render($pool['content']['blob']['content'], $group, $home);
            if ($user === null) {
                $files[] = self::file("/etc/php/{$version}/fpm/pool.d/orbit-scopes.conf", $content, $from($index), 'root', 'root', '0644');
                $validators[] = ['kind' => 'php-fpm', 'version' => $version];
                $units[] = "php{$version}-fpm.service";
            } else {
                $generated = "/etc/orbit/php-fpm/{$user}/generated";
                $files[] = self::file("{$generated}/pool.conf", $content, $from($index), 'root', 'root', '0644');
                $validators[] = ['kind' => 'php-fpm', 'version' => $version, 'fpm_config' => "{$generated}/php-fpm.conf",
                    'ini_scan_dir' => ["/etc/php/{$version}/fpm/conf.d", $generated]];
                $units[] = "orbit-{$user}-php{$version}-fpm.service";
            }
        }

        return ['files' => $files, 'validators' => $validators, 'units' => $units];
    }

    /** A shared pool's text with the account's group and home in place of the render's placeholders. */
    public static function render(string $pool, string $group, string $home): string
    {
        return str_replace(['__ORBIT_NODE_GROUP__', '/__ORBIT_NODE_HOME__'], [$group, rtrim($home, '/')], $pool);
    }

    /**
     * The Caddyfile under the publication lock, validated as `caddy`, then Caddy reloads on every run.
     *
     * @return array<string, mixed>
     */
    public static function caddy(string $content, ?string $from): array
    {
        return self::apply(self::CaddyLock, 'caddy', [self::file(self::Caddyfile, $content, $from, 'root', 'caddy', '0640')],
            [['kind' => 'caddy', 'config' => self::Caddyfile, 'user' => 'caddy']], [['unit' => 'caddy.service', 'load' => 'reload']]);
    }

    /**
     * A VPN Node's records and catalog under the DNS lock, checked as dnsmasq.service loads them, then dnsmasq and the private DNS
     * listener restart on every run.
     *
     * @return array<string, mixed>
     */
    public static function dns(string $records, string $catalog, ?string $recordsFrom, ?string $catalogFrom): array
    {
        return self::apply(self::DnsLock, 'dns', [
            self::file(self::Records, $records, $recordsFrom, 'root', 'root', '0644'),
            self::file(self::Catalog, $catalog, $catalogFrom, 'root', 'root', '0644'),
        ], [['kind' => 'dnsmasq', 'conf_file' => '/etc/dnsmasq.conf', 'conf_dir' => '/etc/dnsmasq.d', 'skip_suffixes' => self::DnsmasqSkip]],
            [['unit' => 'dnsmasq.service', 'load' => 'restart'], ['unit' => 'orbit-private-dns.service', 'load' => 'restart']]);
    }

    /** @return array<string, mixed> */
    public static function plain(string $path, string $content, string $user, string $group, string $mode): array
    {
        return ['state' => 'file', 'path' => $path, 'content' => ['blob' => ['content' => $content]], 'owner' => ['user' => $user, 'group' => $group, 'mode' => $mode]];
    }

    /**
     * A copy of a host file, made on the Node: its bytes never reach the Gateway (file.ensure `source_path`).
     *
     * @return array<string, mixed>
     */
    public static function copy(string $path, string $source, string $user, string $group, string $mode): array
    {
        return ['state' => 'file', 'path' => $path, 'source_path' => $source, 'owner' => ['user' => $user, 'group' => $group, 'mode' => $mode]];
    }

    /** @return array<string, mixed> */
    public static function directory(string $path, string $user, string $group, string $mode): array
    {
        return ['state' => 'directory', 'path' => $path, 'owner' => ['user' => $user, 'group' => $group, 'mode' => $mode]];
    }

    /** @return array{state: string, path: string, target: string, user: string, group: string} */
    public static function link(string $path, string $target, string $user, string $group): array
    {
        return ['state' => 'link', 'path' => $path, 'target' => $target, 'user' => $user, 'group' => $group];
    }

    /** @return array{unit: string, enabled: bool, state: string, budget_ms: int} */
    public static function running(string $unit): array
    {
        return ['unit' => $unit, 'enabled' => true, 'state' => 'running', 'budget_ms' => 30_000];
    }

    /**
     * Caddy's access to one Route's files. PENDING A4 (`posix.acl.ensure` with `scope: tree`, whose `subject` is a user or a group
     * and which runs no `setfacl` as another account): every admitted row is recursive over a checkout or its served roots, or
     * passes through the folders above them (RouteWebRootCommands::access). Once A4 lands, this answers one order per row and
     * path. Until then a Route with access rows runs on Swamp.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function access(array $rows): array
    {
        if ($rows !== []) {
            throw new EngineUnsupported('Caddy access waits on posix.acl.ensure with scope tree (A4); the Route runs on Swamp.');
        }

        return [];
    }

    /** @return array{path: string} */
    public static function read(string $path): array
    {
        return ['path' => $path];
    }

    /**
     * The `from_sha256` a config.apply file takes from its read (`file.read`): the live file's digest, or none when it is
     * absent. A path that is present but no regular file has no digest, so config.apply refuses it unless it already holds the
     * desired bytes. The read's recorded output is fixed once the step is done, so a resend of the write carries the same input.
     */
    public static function digest(?string $read): ?string
    {
        $answer = is_string($read) ? json_decode($read, true) : null;

        return match (true) {
            ! is_array($answer) => throw new \LogicException('A file read has no recorded answer.'),
            ($answer['state'] ?? null) === 'absent' => null,
            ($answer['state'] ?? null) === 'present' => is_string($answer['sha256'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/', $answer['sha256']) === 1 ? $answer['sha256'] : null,
            default => throw new \LogicException('A file read answered an unknown state.'),
        };
    }
}
