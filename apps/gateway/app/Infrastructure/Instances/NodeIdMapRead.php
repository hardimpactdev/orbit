<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\IsolatedIdRanges;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The ID map facts of an Instance host (docs/reference/development-containers.md#isolated-id-ranges): root's subordinate range and
 * the ID map keys of every container on it. One bounded SSH call to the Node runs the fixed reader, as the workspace read does. The
 * Node agent reports neither, and a range is placed by the bases that Incus chose, so only the host can say which gaps are free.
 */
final readonly class NodeIdMapRead
{
    public const float BudgetSeconds = 2.0;

    /** Some hundred containers answer in some tens of KiB. */
    private const int ReadBytes = 1_048_576;

    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $hosts) {}

    /** @return array{ranges: IsolatedIdRanges, containers: list<string>}|null the ranges, and each container as `<project>/<name>`; null when unreadable */
    public function read(Node $node): ?array
    {
        if ($node->status !== LifecycleStatus::Active || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return null;
        }
        try {
            $result = $this->ssh->execute(new SshConnection(
                host: $node->wireguard_ip, user: $node->user, port: 22,
                identityFile: $this->keys->privateKeyPath(), knownHostsFile: $this->hosts->path(),
                connectTimeout: 2, commandTimeout: 4,
            ), new RemoteCommand(
                ['timeout', '3', 'python3', '-'],
                input: 'BUDGET = '.self::BudgetSeconds."\n".File::get(resource_path('instances/id-map-read.py')),
                maxOutputBytes: self::ReadBytes, timeout: 4,
            ));
        } catch (Throwable) {
            return null;
        }

        $read = $result->succeeded() ? self::decode($result->stdout) : null;
        if ($read !== null) {
            // Doctor warns from the last read; it never reaches the Node itself (FactDoctor).
            Node::query()->whereKey($node->id)->update(['id_ranges' => json_encode([...$read['ranges']->summary(), 'read_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)]);
        }

        return $read;
    }

    /** @return array{ranges: IsolatedIdRanges, containers: list<string>}|null */
    public static function decode(string $stdout): ?array
    {
        try {
            $answer = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        $range = static fn (mixed $value): ?array => is_array($value) && array_is_list($value) && count($value) === 2
            && is_int($value[0]) && is_int($value[1]) && $value[0] >= 0 && $value[1] > 0 ? [$value[0], $value[1]] : null;
        if (! is_array($answer) || ($uid = $range($answer['uid'] ?? null)) === null || ($gid = $range($answer['gid'] ?? null)) === null
            || ! is_array($answer['containers'] ?? null)) {
            return null;
        }
        $configs = [];
        $names = [];
        foreach ($answer['containers'] as $container) {
            if (! is_array($container) || ! is_string($container['name'] ?? null) || ! is_string($container['project'] ?? null)
                || ! is_array($container['config'] ?? null)) {
                return null;
            }
            $configs[] = array_filter($container['config'], is_string(...));
            $names[] = $container['project'].'/'.$container['name'];
        }

        return ['ranges' => IsolatedIdRanges::fromHost($uid, $gid, $configs), 'containers' => $names];
    }
}
