<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Builds the task VM base image on one host. A builder VM boots the stock image with the builder user-data, runs the
 * programs that enrollment runs on an `app-dev` Node through its {@see IncusGuestShell}, installs the pinned agent
 * binary and the pinned Pi executable, lets a VM from the image boot once and lean (`lean-boot.sh`), records the
 * programs in the image manifest ({@see TaskVmBakedPrograms}), and removes its identity with `base-image-clean.sh`.
 * Incus publishes its disk as `orbit-task-base-<time>`. A smoke VM boots the new image, which also creates
 * its volume on the ZFS pool. Then the alias `orbit-task-base` moves to it in one
 * request, and older base images that no VM uses go. A failed build keeps the current image and leaves no
 * builder, smoke VM or unused new image behind. {@see IncusTaskVmImageSync} copies the image to the other hosts.
 */
final readonly class IncusTaskVmImageBuilder
{
    /** The Incus templates that write the NoCloud seed. Each one makes the Incus agent reboot a new VM. */
    private const string SeedTemplates = '/var/lib/cloud/seed/';

    /** Orders cloud-init after the Incus agent, whose socket serves the VM's user-data. */
    private const string CloudInitAfterAgent = "[Unit]\nWants=incus-agent.service\nAfter=incus-agent.service\n";

    private const string CloudInitDropIn = '/etc/systemd/system/cloud-init-main.service.d/orbit-incus-agent.conf';

    public function __construct(
        private IncusHost $incus,
        private IncusTaskVmBaseImage $image,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private TaskVmSettings $settings,
        private TaskVmImageRecipe $recipe,
    ) {}

    /** Whether the host's project has a base image. */
    public function exists(TaskVmHost $host, Node $node): bool
    {
        return $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage) !== null;
    }

    /**
     * Builds the host's base image and returns its fingerprint. `$progress` hears each stage with its seconds.
     * `$owner` names the host's image lock, so a caller that is killed can {@see IncusTaskVmBaseImage::abandon()} its
     * own build.
     *
     * @param  (Closure(string, float): void)|null  $progress
     *
     * @throws TaskVmException `task_vm.image_build_running` while another build or sync of the host runs, else `task_vm.image_build_failed`
     */
    public function build(TaskVmHost $host, Node $node, ?Closure $progress = null, ?string $owner = null): string
    {
        $lock = $this->image->lock($node, $owner);

        $stamp = now()->format('YmdHis');
        $builder = TaskVmImageRecipe::WorkPrefix.$stamp;
        $smoke = $builder.'-smoke';
        $alias = TaskVmHost::BaseImage.'-'.$stamp;
        $unused = null;

        try {
            $programs = $this->stage($node, $progress, 'render', fn (): array => $this->recipe->programs());
            $this->stage($node, $progress, 'sweep', fn () => $this->image->sweep($host, $node));
            $this->stage($node, $progress, 'launch', fn () => $this->image->launch($host, $node, $builder, TaskVmHost::SourceImage, $this->cloudInit->renderImageBuilder($this->keys->publicKey())));
            $this->stage($node, $progress, 'cloud-init', fn () => $this->image->awaitCloudInit($host, $node, $builder));
            $shell = $this->image->shell($host, $node, $builder);
            $this->stage($node, $progress, 'orbit-user', fn () => $this->checked($shell, 'orbit-user', $shell->asRoot(TaskVmImageRecipe::ownerCheck())));
            foreach ($programs as $name => $program) {
                $this->stage($node, $progress, $name, fn () => $this->checked($shell, $name, $shell->asUser($program->arguments, $program->protectedInput ?? $program->input)));
            }
            $this->stage($node, $progress, 'agent-binary', fn () => $this->checked($shell, 'agent-binary', $shell->asRoot($this->recipe->installAgent(
                $this->checked($shell, 'uname', $shell->asRoot(['uname', '-m']))->stdout,
                "the base image build VM [{$builder}]",
            ))));
            if ($this->settings->piArtifactPath !== null && $this->settings->piArtifactSha256 !== null) {
                $this->stage($node, $progress, 'pi-artifact', fn () => $this->checked($shell, 'pi-artifact', $shell->asUser(
                    ['bash', '-ceu', TaskVmRuntime::InstallPi, '--', (string) $this->settings->piArtifactSha256],
                    ProtectedInput::fromFile((string) $this->settings->piArtifactPath, '', TaskVmRuntime::ArtifactMaxBytes),
                )));
            }
            $this->stage($node, $progress, 'single-boot', fn () => $this->singleBoot($host, $node, $shell));
            $this->stage($node, $progress, 'lean-boot', fn () => $this->checked($shell, 'lean-boot', $shell->asRoot(['bash', '-ceu', TaskVmScripts::read('lean-boot.sh').TaskVmImageRecipe::Masks])));
            $this->stage($node, $progress, 'manifest', fn () => $this->checked($shell, 'manifest', $shell->asRoot([
                'install', '-D', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', TaskVmBakedPrograms::ManifestPath,
            ], TaskVmBakedPrograms::manifest($programs))));
            $this->stage($node, $progress, 'clean', fn () => $this->clean($shell));
            $unused = $published = $this->stage($node, $progress, 'publish', fn (): string => $this->publish($host, $node, $builder, $alias));
            $this->stage($node, $progress, 'delete-builder', fn () => $this->image->delete($host, $node, $builder));
            $this->stage($node, $progress, 'smoke', fn () => $this->image->smoke($host, $node, $smoke, $alias, $this->checkImage(...)));
            $this->stage($node, $progress, 'promote', fn () => $this->image->promote($host, $node, $published));
            // The new image is the base image now, so a failed prune leaves it in place.
            $unused = null;
            $this->stage($node, $progress, 'prune', fn (): array => $this->image->prune($host, $node, $published));

            return $published;
        } catch (Throwable $exception) {
            $this->image->discard($host, $node, [$builder, $smoke], $unused);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * @template T
     *
     * @param  (Closure(string, float): void)|null  $progress
     * @param  Closure(): T  $work
     * @return T
     */
    private function stage(Node $node, ?Closure $progress, string $name, Closure $work): mixed
    {
        return TaskVmBuildStage::run('task_vm.image_build_failed', "base image build of Node [{$node->name}]", $name, $progress, $work);
    }

    /** Fails with the stage's name when a command in the builder failed. */
    private function checked(IncusGuestShell $shell, string $stage, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw IncusHost::failed("the base image build VM [{$shell->vm}]", "exec {$stage}", $result);
    }

    /**
     * Lets a VM from the image boot once. The image's Incus templates write the NoCloud seed, and the Incus
     * agent reboots a new VM after it writes the seed, because cloud-init has started by then. Without those
     * templates, cloud-init reads the user-data from the agent's socket through its `LXD` data source, so it
     * must wait for the agent. The other templates, such as `/etc/hostname`, stay.
     */
    public function singleBoot(TaskVmHost $host, Node $node, IncusGuestShell $shell): void
    {
        $this->checked($shell, 'cloud-init drop-in', $shell->asRoot([
            'install', '-D', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', self::CloudInitDropIn,
        ], self::CloudInitAfterAgent));

        $name = $shell->vm;
        $subject = "the base image build VM [{$name}]";
        $shown = $this->image->checked($name, 'config metadata show', $this->incus->run($host, $node, ['config', 'metadata', 'show', '--', $name]));
        // Maps stay objects, so an empty map such as `properties: {}` keeps its type for Incus.
        try {
            $metadata = $shown->truncated ? null : Yaml::parse($shown->stdout, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException) {
            $metadata = null;
        }
        $templates = $metadata instanceof stdClass ? $metadata->templates ?? null : null;
        if (! $templates instanceof stdClass) {
            throw IncusHost::invalid($subject, 'the image metadata has no templates');
        }
        foreach (array_keys(get_object_vars($templates)) as $path) {
            if (str_starts_with($path, self::SeedTemplates)) {
                unset($templates->{$path});
            }
        }
        $this->image->checked($name, 'config metadata edit', $this->incus->run($host, $node, ['config', 'metadata', 'edit', '--', $name], Yaml::dump($metadata, 8, 2, Yaml::DUMP_OBJECT_AS_MAP)));
    }

    /**
     * A VM from the new image kept the lean boot through its first boot, when systemd applies the preset
     * policy, holds the manifest that enrollment reads, and has the managed user with the pinned uid and gid.
     */
    private function checkImage(IncusGuestShell $smoke): void
    {
        if (! $smoke->asRoot(TaskVmImageRecipe::imageCheck(['--lean-boot']))->succeeded()) {
            throw IncusHost::invalid("the base image smoke VM [{$smoke->vm}]", 'it did not keep the lean boot, the image manifest, or the pinned '.TaskVmCloudInit::User.' uid and gid');
        }
    }

    public function clean(IncusGuestShell $shell): void
    {
        $result = $shell->asRoot(['bash', '-s'], $this->recipe->cleanScript());
        if (! $result->succeeded() || trim($result->stdout) !== TaskVmImageRecipe::CleanConfirmation) {
            throw IncusHost::failed("the base image build VM [{$shell->vm}]", 'exec base-image-clean.sh', $result);
        }
    }

    /** Stops the builder and publishes its disk under `$alias`. Returns the new fingerprint. */
    public function publish(TaskVmHost $host, Node $node, string $name, string $alias): string
    {
        $stopped = $this->incus->run($host, $node, ['stop', '--', $name]);
        if (! $stopped->succeeded()) {
            // An interrupted publish may already have stopped the VM. Only a confirmed stopped instance is safe.
            $listed = $this->image->checked($name, 'list', $this->incus->run($host, $node, ['list', '--format', 'json']));
            $instances = IncusHost::json($name, $listed);
            $alreadyStopped = array_any($instances, static fn (mixed $instance): bool => is_array($instance) && ($instance['name'] ?? null) === $name && ($instance['status'] ?? null) === 'Stopped');
            if (! $alreadyStopped) {
                $this->image->checked($name, 'stop', $stopped);
            }
        }
        $this->image->checked($name, 'publish', $this->incus->run($host, $node, ['publish', '--compression', 'none', '--alias', $alias, '--', $name]));

        return $this->incus->aliasTarget($host, $node, $alias)
            ?? throw IncusHost::invalid("the base image build VM [{$name}]", "the published image has no alias [{$alias}]");
    }
}
