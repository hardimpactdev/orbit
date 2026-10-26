<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseInventory;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseObserver;

/**
 * Reads the release inventory from the layout as the Gateway account, without the agent. Status, deploy, and rollback
 * read release state here, so a Gateway whose own agent is older than its code still answers and can release.
 * A layout entry that another account owns refuses the read, because the Gateway account owns the whole layout.
 */
final readonly class FilesystemGatewayReleaseObserver implements GatewayReleaseObserver
{
    public function inspect(GatewayReleaseLayout $layout, ?string $runningApplication = null): GatewayReleaseInventory
    {
        if ($runningApplication !== null && (! str_starts_with($runningApplication, '/') || in_array('..', explode('/', $runningApplication), true))) {
            throw $this->failed();
        }

        $currentPath = $layout->currentPath();
        $current = $this->owned($currentPath);
        $superseded = false;
        if ($current !== null && $this->isLink($current) && $runningApplication !== null) {
            $serving = realpath($currentPath.'/apps/gateway');
            $running = realpath($runningApplication);
            $superseded = $serving !== false && $running !== false && $serving !== $running;
        }

        $currentId = $this->releaseId($layout);
        $state = $current !== null && $this->isDirectory($current) ? 'in_place' : 'unreadable';
        $commits = $incomplete = $modified = [];
        $releases = $this->owned($layout->releasesPath());

        if ($releases !== null) {
            if (! $this->isDirectory($releases)) {
                throw $this->failed();
            }
            foreach (scandir($layout->releasesPath()) ?: throw $this->failed() as $id) {
                $path = $layout->releasesPath().'/'.$id;
                if (! GatewayReleaseCommit::isId($id) || ! $this->isDirectory($this->owned($path))) {
                    continue;
                }
                $revision = $this->owned($path.'/REVISION');
                $sha = $this->preparedCommit($layout, $id);
                if ($sha !== null && $revision !== null) {
                    $commits[$id] = $sha;
                    $modified[$id] = $revision['mtime'];
                } elseif ($revision === null) {
                    $incomplete[$id] = (int) ($this->owned($path)['mtime'] ?? 0);
                }
            }
        }

        // Newest first by preparation time, with the id as the tie-breaker.
        uksort($modified, static fn (string $left, string $right): int => [$modified[$right], $right] <=> [$modified[$left], $left]);
        if ($currentId !== null && isset($commits[$currentId])) {
            $state = 'adopted';
        }

        return new GatewayReleaseInventory($currentId, $state, $superseded, $commits, $incomplete, array_map(strval(...), array_keys($modified)));
    }

    /** The release the current path links to, or null for an in-place checkout or a link to anything else. */
    private function releaseId(GatewayReleaseLayout $layout): ?string
    {
        $current = $this->owned($layout->currentPath());
        if ($current === null || ! $this->isLink($current)) {
            return null;
        }
        $target = readlink($layout->currentPath());
        if ($target === false) {
            throw $this->failed();
        }
        foreach ([$layout->releasesPath().'/', 'releases/'] as $prefix) {
            if (str_starts_with($target, $prefix) && GatewayReleaseCommit::isId(substr($target, strlen($prefix)))) {
                return substr($target, strlen($prefix));
            }
        }

        return null;
    }

    /** The full commit in a release's `REVISION`, which prepare writes last, or null for an unfinished release. */
    private function preparedCommit(GatewayReleaseLayout $layout, string $id): ?string
    {
        $revision = $this->owned($layout->releasePath($id).'/REVISION');
        if ($revision === null || ($revision['mode'] & 0170000) !== 0100000 || $revision['size'] > 128) {
            return null;
        }
        $sha = trim((string) file_get_contents($layout->releasePath($id).'/REVISION'));

        return GatewayReleaseCommit::isSha($sha) && str_starts_with($sha, $id) ? $sha : null;
    }

    /**
     * The entry's own status, without following a link, or null when it is absent.
     *
     * @return array<string|int, int>|null
     */
    private function owned(string $path): ?array
    {
        clearstatcache(true, $path);
        $status = @lstat($path);
        if ($status === false) {
            return null;
        }
        if ($status['uid'] !== posix_geteuid()) {
            throw $this->failed();
        }

        return $status;
    }

    /** @param array<string|int, int>|null $status */
    private function isDirectory(?array $status): bool
    {
        return $status !== null && ($status['mode'] & 0170000) === 0040000;
    }

    /** @param array<string|int, int> $status */
    private function isLink(array $status): bool
    {
        return ($status['mode'] & 0170000) === 0120000;
    }

    private function failed(): GatewayReleaseException
    {
        return new GatewayReleaseException('inspect', 'gateway.release_observation_failed', 'The release layout cannot be read as the Gateway account.', 500);
    }
}
