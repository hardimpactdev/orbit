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
 * Copies one host's base image to other hosts, so every host launches the same image. The source exports the
 * image into a private directory and streams it over SSH to the receiver of the target, which imports it; see
 * {@see TaskVmImagePeers}. The image never passes through the Gateway. Each target then checks that the image has
 * the source's fingerprint, boots a smoke VM from it, moves `orbit-task-base` to it in one request, and deletes
 * older base images that no VM uses. The source is only read. A failed target keeps its current image and leaves
 * no smoke VM or unused copy behind.
 */
final readonly class IncusTaskVmImageSync
{
    /** Holds the export for one target on the source host, so a later sync to that target removes a leftover. */
    public const string ExportDirectory = TaskVmImagePeers::WorkDirectory.'/export';

    /** An export of about 3 GiB takes a few seconds on a local disk. */
    private const float ExportSeconds = 300.0;

    /**
     * An image of about 3 GiB takes about half a minute on a 1 Gbit/s LAN, plus the import. The export, the transfer,
     * and a smoke boot of up to 600 seconds stay within the host's 1500-second image lock.
     */
    private const float TransferSeconds = 540.0;

    /**
     * Exports the image `$2` of the project `$3` into the empty directory `$1`, which only root reads, and prints the
     * path of the one file it writes.
     */
    private const string Export = <<<'BASH'
        install -d -m 0700 -o root -g root -- "$(dirname -- "$(dirname -- "$1")")" "$(dirname -- "$1")"
        rm -rf -- "$1"
        install -d -m 0700 -o root -g root -- "$1"
        incus image export --quiet --project "$3" "$2" "$1/image" >&2
        set -- "$1"/image*
        if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then
            echo "the export is not one image file: $*" >&2
            exit 1
        fi
        printf '%s\n' "$1"
        BASH;

    public function __construct(
        private IncusHost $incus,
        private TaskVmBaseImage $image,
        private TaskVmSettings $settings,
        private TaskVmImagePeers $peers,
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
                try {
                    $file = $this->stage($work, $progress, 'export', fn (): string => $this->export($sourceHost, $source, $node, $fingerprint));
                    $this->stage($work, $progress, 'transfer', fn () => $this->transfer($source, $host, $node, $fingerprint, $file));
                } finally {
                    $this->removeExport($source, $node);
                }
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

    /** The private directory on the source that holds the export for `$target`. */
    public static function exportDirectory(Node $target): string
    {
        return self::ExportDirectory.'/node-'.$target->id;
    }

    /** Exports the image on the source and returns the path of the image file. */
    private function export(TaskVmHost $sourceHost, Node $source, Node $target, string $fingerprint): string
    {
        $directory = self::exportDirectory($target);
        $result = $this->incus->root($source, ['bash', '-ceu', self::Export, '--', $directory, $fingerprint, $sourceHost->project], timeout: self::ExportSeconds);
        if (! $result->succeeded()) {
            throw TaskVmImagePeers::failed("The export of the image [{$fingerprint}] on Node [{$source->name}] failed", $result);
        }
        $file = trim($result->stdout);
        if (! str_starts_with($file, $directory.'/image') || str_contains($file, "\n") || str_contains(substr($file, strlen($directory)), '..')) {
            throw IncusHost::invalid("the image export on Node [{$source->name}]", 'it printed no image file in its directory');
        }

        return $file;
    }

    /**
     * Streams the image to the receiver of the target, which imports it. A failed transfer can still have imported
     * the image, so the target's images are read again before the transfer counts as failed.
     */
    private function transfer(Node $source, TaskVmHost $host, Node $node, string $fingerprint, string $file): void
    {
        $result = $this->peers->send($source, $node, $file, self::TransferSeconds);
        $received = $result->succeeded() ? trim($result->stdout) : null;
        if ($received === $fingerprint) {
            return;
        }
        if ($received !== null) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $received) === 1 && $received !== $fingerprint) {
                $this->incus->run($host, $node, ['image', 'delete', '--', $received]);
            }

            throw IncusHost::invalid("the image transfer to Node [{$node->name}]", "the receiver imported the image [{$received}], not the source image [{$fingerprint}]");
        }
        if (array_key_exists($fingerprint, $this->incus->images($host, $node))) {
            Log::warning('The task VM base image transfer failed, but the target has the image.', [
                'node' => $node->name, 'source' => $source->name, 'fingerprint' => $fingerprint, 'exit_code' => $result->exitCode, 'stderr' => mb_substr(trim($result->stderr), -2000),
            ]);

            return;
        }

        throw TaskVmImagePeers::failed("The transfer of the image [{$fingerprint}] from Node [{$source->name}] to Node [{$node->name}] failed", $result);
    }

    /** Removes the export. A failure only logs: the next sync to the target removes the directory first. */
    private function removeExport(Node $source, Node $target): void
    {
        $result = $this->incus->root($source, ['rm', '-rf', '--', self::exportDirectory($target)]);
        if (! $result->succeeded()) {
            Log::warning('The task VM base image export could not be removed.', ['node' => $source->name, 'target' => $target->name, 'stderr' => trim($result->stderr)]);
        }
    }

    /**
     * The target must hold the source's image: Incus names an image by the SHA-256 of its content. The dated alias
     * of the source goes on it.
     */
    private function verify(TaskVmHost $host, Node $node, string $alias, string $fingerprint, bool $copied): void
    {
        $images = $this->incus->images($host, $node);
        if (! array_key_exists($fingerprint, $images)) {
            throw IncusHost::invalid("the base image on Node [{$node->name}]", "it has no image [{$fingerprint}] after the transfer");
        }
        if (! in_array($alias, $images[$fingerprint], true)) {
            $this->image->checked($alias, 'image alias create', $this->incus->run($host, $node, ['image', 'alias', 'create', '--', $alias, $fingerprint]));
        }
        $actual = $this->incus->aliasTarget($host, $node, $alias);
        if ($actual === $fingerprint) {
            return;
        }
        if ($copied && $actual !== null) {
            $this->incus->run($host, $node, ['image', 'delete', '--', $actual]);
        }

        throw IncusHost::invalid("the base image on Node [{$node->name}]", "the alias [{$alias}] names the image [".($actual ?? 'none')."], not the source image [{$fingerprint}]");
    }
}
