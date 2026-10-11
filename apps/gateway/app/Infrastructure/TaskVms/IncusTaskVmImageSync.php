<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageSyncResult;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Node;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies one host's base image to other hosts, so every host launches the same image. The target host pulls
 * the image from the source host's Incus API with `incus image copy`, over the address that
 * {@see TaskVmImagePeers} set up; the image never passes through the Gateway. Each target then checks that its
 * copy has the source's fingerprint, boots a smoke VM from it, moves `orbit-task-base` to it in one request,
 * and deletes older base images that no VM uses. The source is only read. A failed target keeps its current
 * image and leaves no smoke VM or unused copy behind.
 */
final readonly class IncusTaskVmImageSync
{
    /** An image of about 3 GiB takes about half a minute on a 1 Gbit/s LAN. */
    private const float CopySeconds = 1800.0;

    public function __construct(
        private IncusHost $incus,
        private TaskVmBaseImage $image,
        private TaskVmSettings $settings,
    ) {}

    /**
     * The fingerprint of the source host's current base image.
     *
     * @throws TaskVmException `task_vm.base_image_missing` when the host has none
     */
    public function current(TaskVmHost $host, Node $node): string
    {
        return $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage)
            ?? throw new TaskVmException('task_vm.base_image_missing', "Node [{$node->name}] has no base image [".TaskVmHost::BaseImage.']. Build it with task-vms:build-image.');
    }

    /**
     * Syncs every other configured host from `$source`, one after another. A host that fails keeps its image and
     * does not stop the others. A configured host whose Node is not active fails too.
     *
     * @param  (Closure(string, string, float): void)|null  $progress  hears the Node name, the stage, and its seconds
     * @return list<TaskVmImageSyncResult>
     */
    public function distribute(TaskVmHost $sourceHost, Node $source, string $fingerprint, ?Closure $progress = null, ?string $owner = null): array
    {
        $results = [];
        foreach ($this->settings->hosts as $host) {
            if ($host->nodeId === $source->id) {
                continue;
            }
            $node = Node::query()->find($host->nodeId);
            $name = $node instanceof Node ? $node->name : (string) $host->nodeId;
            $started = microtime(true);
            $stages = [];
            try {
                if (! $node instanceof Node || $node->status !== LifecycleStatus::Active) {
                    throw new TaskVmException('task_vm.unknown_host', "Node [{$name}] is not an active Node.");
                }
                $copied = $this->sync($sourceHost, $source, $host, $node, $fingerprint, function (string $stage, float $seconds) use (&$stages, $progress, $name): void {
                    $stages[$stage] = $seconds;
                    if ($progress instanceof Closure) {
                        $progress($name, $stage, $seconds);
                    }
                }, $owner);
                $results[] = new TaskVmImageSyncResult($name, $copied, round(microtime(true) - $started, 1), $stages);
            } catch (Throwable $exception) {
                Log::error('The task VM base image sync failed.', ['node' => $name, 'source' => $source->name, 'error' => $exception->getMessage()]);
                $results[] = new TaskVmImageSyncResult($name, false, round(microtime(true) - $started, 1), $stages, TaskVmException::codeOf($exception), $exception->getMessage());
            }
        }

        return $results;
    }

    /**
     * Makes `$fingerprint` from `$source` the base image of `$node`. Returns false when it already is.
     * `$owner` names the target's image lock, so a caller that is killed can {@see TaskVmBaseImage::abandon()}.
     *
     * @param  (Closure(string, float): void)|null  $progress
     *
     * @throws TaskVmException `task_vm.image_build_running` while another build or sync of the target runs, else `task_vm.image_sync_failed`
     */
    public function sync(TaskVmHost $sourceHost, Node $source, TaskVmHost $host, Node $node, string $fingerprint, ?Closure $progress = null, ?string $owner = null): bool
    {
        if ($source->id === $node->id) {
            throw new TaskVmException('task_vm.image_sync_failed', "Node [{$node->name}] is the source of the base image sync.");
        }
        $lock = $this->image->lock($node, $owner);

        $smoke = TaskVmBaseImage::WorkPrefix.now()->format('YmdHis').'-sync-smoke';
        $copied = null;
        $work = "base image sync of Node [{$node->name}] from Node [{$source->name}]";

        try {
            [$alias, $images] = $this->stage($work, $progress, 'inspect', fn (): array => [$this->datedAlias($sourceHost, $source, $fingerprint), $this->incus->images($host, $node)]);
            if (in_array(TaskVmHost::BaseImage, $images[$fingerprint] ?? [], true)) {
                return false;
            }
            $this->stage($work, $progress, 'sweep', fn () => $this->image->sweep($host, $node));
            if (! array_key_exists($fingerprint, $images)) {
                $copied = $fingerprint;
                $this->stage($work, $progress, 'copy', fn () => $this->copy($sourceHost, $source, $host, $node, $fingerprint, $alias));
            } elseif (! in_array($alias, $images[$fingerprint], true)) {
                $this->stage($work, $progress, 'copy', fn () => $this->image->checked($alias, 'image alias create', $this->incus->run($host, $node, ['image', 'alias', 'create', '--', $alias, $fingerprint])));
            }
            $this->stage($work, $progress, 'verify', fn () => $this->verify($host, $node, $alias, $fingerprint, $copied !== null));
            $this->stage($work, $progress, 'smoke', fn () => $this->image->smoke($host, $node, $smoke, $alias));
            $this->stage($work, $progress, 'promote', fn () => $this->image->promote($host, $node, $fingerprint));
            // The copy is the base image now, so a failed prune leaves it in place.
            $copied = null;
            $this->stage($work, $progress, 'prune', fn (): array => $this->image->prune($host, $node, $fingerprint));

            return true;
        } catch (Throwable $exception) {
            $this->image->discard($host, $node, [$smoke], $copied);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * @template T
     *
     * @param  (Closure(string, float): void)|null  $progress
     * @param  Closure(): T  $stage
     * @return T
     */
    private function stage(string $work, ?Closure $progress, string $name, Closure $stage): mixed
    {
        return $this->image->stage('task_vm.image_sync_failed', $work, $name, $progress, $stage);
    }

    /** The source image's dated alias `orbit-task-base-<time>`, which the copy keeps. */
    private function datedAlias(TaskVmHost $host, Node $node, string $fingerprint): string
    {
        $aliases = $this->incus->images($host, $node)[$fingerprint]
            ?? throw new TaskVmException('task_vm.base_image_missing', "Node [{$node->name}] has no image [{$fingerprint}].");
        foreach ($aliases as $alias) {
            if (str_starts_with($alias, TaskVmHost::BaseImage.'-')) {
                return $alias;
            }
        }

        throw IncusHost::invalid("the base image [{$fingerprint}] of Node [{$node->name}]", 'it has no dated alias');
    }

    /**
     * The target pulls the image from the source's Incus API, with its own client certificate and the source's
     * pinned server certificate. The Gateway only starts the command.
     */
    private function copy(TaskVmHost $sourceHost, Node $source, TaskVmHost $host, Node $node, string $fingerprint, string $alias): void
    {
        $result = $this->incus->root($node, [
            'env', 'INCUS_CONF='.TaskVmImagePeers::ClientDirectory,
            'incus', 'image', 'copy', '--quiet', '--project', $sourceHost->project, '--target-project', $host->project,
            TaskVmImagePeers::remote($source).':'.$fingerprint, 'local:', '--alias', $alias,
        ], timeout: self::CopySeconds);
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image of Node [{$node->name}] from Node [{$source->name}]", 'image copy', $result);
        }
    }

    /** The copy must be the source's image: Incus names an image by the SHA-256 of its content. */
    private function verify(TaskVmHost $host, Node $node, string $alias, string $fingerprint, bool $copied): void
    {
        $actual = $this->incus->aliasTarget($host, $node, $alias);
        if ($actual === $fingerprint) {
            return;
        }
        if ($copied && $actual !== null) {
            $this->incus->run($host, $node, ['image', 'delete', '--', $actual]);
        }

        throw IncusHost::invalid("the base image copy on Node [{$node->name}]", "the alias [{$alias}] names the image [".($actual ?? 'none')."], not the source image [{$fingerprint}]");
    }
}
