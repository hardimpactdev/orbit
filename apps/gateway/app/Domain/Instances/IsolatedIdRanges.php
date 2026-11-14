<?php

declare(strict_types=1);

namespace App\Domain\Instances;

/**
 * The isolated ID ranges of an Instance host, placed as Incus 7.0.2 places them (docs/reference/development-containers.md#isolated-id-ranges).
 * Incus gives each isolated container, and each new snapshot of one, a range of root's one subordinate range: the first gap that
 * fits, from 65,536 IDs past root's base (`findIdmap` in `internal/server/instance/drivers/driver_lxc.go`). A fixed-base container
 * holds its range too; a privileged or shared-map container holds none. Stopped containers hold theirs.
 */
final readonly class IsolatedIdRanges
{
    /** The first 65,536 IDs of root's range stay with the host. */
    private const int HostShare = 65536;

    /**
     * @param  array{0: int, 1: int}  $root  root's subordinate range: its base and its count
     * @param  list<array{0: int, 1: int}>  $held  each held range: its base and its size
     */
    public function __construct(public array $root, public array $held) {}

    /**
     * The ranges that the read containers hold, by Incus's own rules. Root's range is the part that /etc/subuid and /etc/subgid share.
     *
     * @param  array{0: int, 1: int}  $uid
     * @param  array{0: int, 1: int}  $gid
     * @param  list<array<string, string>>  $configs  each container's ID map keys
     */
    public static function fromHost(array $uid, array $gid, array $configs): self
    {
        $base = max($uid[0], $gid[0]);
        $root = [$base, max(0, min($uid[0] + $uid[1], $gid[0] + $gid[1]) - $base)];
        $held = [];
        foreach ($configs as $config) {
            if (in_array(strtolower($config['security.privileged'] ?? ''), ['true', '1', 'yes', 'on'], true)) {
                continue;
            }
            $fixed = $config['security.idmap.base'] ?? '';
            $isolated = in_array(strtolower($config['security.idmap.isolated'] ?? ''), ['true', '1', 'yes', 'on'], true);
            $start = $fixed !== '' ? $fixed : ($isolated ? ($config['volatile.idmap.base'] ?? '') : '');
            if (preg_match('/\A[0-9]+\z/', $start) !== 1) {
                continue;
            }
            $size = $config['security.idmap.size'] ?? '';
            $held[] = [(int) $start, preg_match('/\A[1-9][0-9]*\z/', $size) === 1 ? (int) $size : self::HostShare];
        }

        return new self($root, $held);
    }

    /** The base that Incus would give a new range of this size now, or null when no gap fits it. */
    public function place(int $size): ?int
    {
        $entries = $this->held;
        usort($entries, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $offset = $this->root[0] + self::HostShare;
        foreach ($entries as $i => [$base, $length]) {
            if ($i === 0) {
                if ($base < $offset + $size) {
                    $offset = $base + $length;

                    continue;
                }

                return $offset;
            }
            [$previous, $previousLength] = $entries[$i - 1];
            if ($previous + $previousLength > $offset) {
                $offset = $previous + $previousLength;

                continue;
            }
            $offset = $previous + $previousLength;
            if ($offset + $size <= $base) {
                return $offset;
            }
            $offset = $base + $length;
        }

        return $offset + $size <= $this->root[0] + $this->root[1] ? $offset : null;
    }

    /** These ranges with one more of this size placed, or null when it does not fit. */
    public function with(int $size): ?self
    {
        $base = $this->place($size);

        return $base === null ? null : new self($this->root, [...$this->held, [$base, $size]]);
    }

    /**
     * What Doctor keeps of a read (docs/reference/development-containers.md#isolated-id-ranges): how many full nested-size ranges are
     * free, up to 2, and how many normal containers still fit before one would land in a full-size gap and split it, up to 10.
     *
     * @return array{full: int, small: int}
     */
    public function summary(): array
    {
        $full = $this->free(InstanceHostIntent::TaskPlaceIds, 2);
        $small = 0;
        $ranges = $this;
        while ($small < 10 && ($ranges = $ranges->with(InstanceHostIntent::IdMapSize)) !== null && $ranges->free(InstanceHostIntent::TaskPlaceIds, 2) === $full) {
            $small++;
        }

        return ['full' => $full, 'small' => $small];
    }

    /** How many more ranges of this size fit, one after another, up to `$limit`. */
    public function free(int $size, int $limit = 1000): int
    {
        $count = 0;
        $ranges = $this;
        while ($count < $limit && ($ranges = $ranges->with($size)) !== null) {
            $count++;
        }

        return $count;
    }
}
