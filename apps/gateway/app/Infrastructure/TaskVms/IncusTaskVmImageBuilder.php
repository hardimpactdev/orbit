<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Builds the task VM base image on one host. A builder VM boots the stock image with the builder user-data,
 * runs the programs that enrollment runs on an `app-dev` Node through `incus exec`, installs the pinned agent
 * binary and the pinned Pi executable, lets a VM from the image boot once, and removes its identity with
 * `base-image-clean.sh`. Incus publishes its disk as `orbit-task-base-<time>`. A smoke VM boots the new
 * image, which also creates its volume on the ZFS pool. Then the alias `orbit-task-base` moves to it in one
 * request, and older base images that no VM uses go. A failed build keeps the current image and leaves no
 * builder, smoke VM or unused new image behind. {@see IncusTaskVmImageSync} copies the image to the other hosts.
 */
final readonly class IncusTaskVmImageBuilder
{
    private const string CleanScript = 'task-vms/base-image-clean.sh';

    private const string Confirmation = '{"ok":true}';

    /** The Incus templates that write the NoCloud seed. Each one makes the Incus agent reboot a new VM. */
    private const string SeedTemplates = '/var/lib/cloud/seed/';

    /** Orders cloud-init after the Incus agent, whose socket serves the VM's user-data. */
    private const string CloudInitAfterAgent = "[Unit]\nWants=incus-agent.service\nAfter=incus-agent.service\n";

    private const string CloudInitDropIn = '/etc/systemd/system/cloud-init-main.service.d/orbit-incus-agent.conf';

    private const string InstallAgent = <<<'BASH'
        candidate=/usr/local/bin/orbit-agent.orbit-candidate
        curl --fail --location --silent --show-error --connect-timeout 10 --max-time 300 --output "$candidate" -- "$1"
        printf '%s  %s\n' "$2" "$candidate" | sha256sum --check --status
        chown root:root -- "$candidate"
        chmod 0755 -- "$candidate"
        mv -fT -- "$candidate" /usr/local/bin/orbit-agent
        BASH;

    public function __construct(
        private IncusHost $incus,
        private TaskVmBaseImage $image,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private NodeBootstrapCommandFactory $bootstrap,
        private NodeRolePrerequisiteCommandFactory $roles,
        private TaskVmSettings $settings,
    ) {}

    /** Whether the host's project has a base image. */
    public function exists(TaskVmHost $host, Node $node): bool
    {
        return $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage) !== null;
    }

    /**
     * Builds the host's base image and returns its fingerprint. `$progress` hears each stage with its seconds.
     * `$owner` names the host's image lock, so a caller that is killed can {@see TaskVmBaseImage::abandon()} its
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
        $builder = TaskVmBaseImage::WorkPrefix.$stamp;
        $smoke = $builder.'-smoke';
        $alias = TaskVmHost::BaseImage.'-'.$stamp;
        $unused = null;

        try {
            $programs = $this->stage($node, $progress, 'render', fn (): array => $this->enrollmentPrograms());
            $this->stage($node, $progress, 'sweep', fn () => $this->image->sweep($host, $node));
            $this->stage($node, $progress, 'launch', fn () => $this->image->launch($host, $node, $builder, TaskVmHost::SourceImage, $this->cloudInit->renderImageBuilder($this->keys->publicKey())));
            $this->stage($node, $progress, 'cloud-init', fn () => $this->image->awaitCloudInit($host, $node, $builder));
            foreach ($programs as $name => $program) {
                $this->stage($node, $progress, $name, fn () => $this->runProgram($host, $node, $builder, $name, $program));
            }
            $this->stage($node, $progress, 'agent-binary', fn () => $this->installAgent($host, $node, $builder));
            if ($this->settings->piArtifactPath !== null && $this->settings->piArtifactSha256 !== null) {
                $this->stage($node, $progress, 'pi-artifact', fn () => $this->installPi($host, $node, $builder, (string) $this->settings->piArtifactPath, (string) $this->settings->piArtifactSha256));
            }
            $this->stage($node, $progress, 'single-boot', fn () => $this->singleBoot($host, $node, $builder));
            $this->stage($node, $progress, 'clean', fn () => $this->clean($host, $node, $builder));
            $unused = $published = $this->stage($node, $progress, 'publish', fn (): string => $this->publish($host, $node, $builder, $alias));
            $this->stage($node, $progress, 'delete-builder', fn () => $this->image->delete($host, $node, $builder));
            $this->stage($node, $progress, 'smoke', fn () => $this->image->smoke($host, $node, $smoke, $alias));
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
        return $this->image->stage('task_vm.image_build_failed', "base image build of Node [{$node->name}]", $name, $progress, $work);
    }

    /**
     * The programs that enrollment runs on an `app-dev` Node, rendered by the same factories, as root.
     *
     * @return array<string, RemoteCommand>
     */
    private function enrollmentPrograms(): array
    {
        $node = new Node(['name' => 'task-vm-base-image', 'platform' => 'linux', 'user' => 'orbit']);
        $programs = ['bootstrap' => $this->bootstrap->makeWithPasswordlessSudo($node, 'orbit')];
        $caddy = $this->roles->caddyPackage($node, RoleName::AppDev);
        if ($caddy instanceof RemoteCommand) {
            $programs['caddy-package'] = $caddy;
        }
        $programs['app-dev-prerequisites'] = $this->roles->make($node, RoleName::AppDev, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

        return $programs;
    }

    /**
     * Runs one enrollment program as `orbit` from its home, as enrollment does over SSH: each program
     * starts with `sudo`, and the Vite+ installer needs a working directory that `orbit` can read.
     */
    private function runProgram(TaskVmHost $host, Node $node, string $name, string $stage, RemoteCommand $program): void
    {
        $result = $this->incus->run($host, $node, [
            'exec', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit',
            '--', $name, 'runuser', '-u', 'orbit', '--', ...$program->arguments,
        ], $program->input);
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image build VM [{$name}]", "exec {$stage}", $result);
        }
    }

    /** Installs the pinned Node agent binary for the VM's architecture. The agent itself is configured at enrollment. */
    private function installAgent(TaskVmHost $host, Node $node, string $name): void
    {
        $architecture = trim($this->image->checked($name, 'exec uname', $this->image->exec($host, $node, $name, ['uname', '-m']))->stdout);
        if (! MachineArchitecture::isValid($architecture) || ! in_array($architecture, NodeAgentFootprint::Architectures, true)) {
            throw IncusHost::invalid("the base image build VM [{$name}]", 'the machine architecture is not a Node agent architecture');
        }
        $this->image->checked($name, 'exec agent-binary', $this->image->exec($host, $node, $name, [
            'bash', '-ceu', self::InstallAgent, '--', NodeAgentFootprint::downloadUrl($architecture), NodeAgentFootprint::checksum($architecture),
        ]));
    }

    /**
     * Installs the Gateway's pinned Pi executable for `orbit`, with the script that prepares Pi on a task VM.
     * A VM from the image then skips the upload while the pin is the same.
     */
    private function installPi(TaskVmHost $host, Node $node, string $name, string $artifact, string $digest): void
    {
        $this->image->checked($name, 'exec pi-artifact', $this->incus->run($host, $node, [
            'exec', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit',
            '--', $name, 'runuser', '-u', 'orbit', '--', 'bash', '-ceu', TaskVmRuntime::InstallPi, '--', $digest,
        ], ProtectedInput::fromFile($artifact, '', TaskVmRuntime::ArtifactMaxBytes)));
    }

    /**
     * Lets a VM from the image boot once. The image's Incus templates write the NoCloud seed, and the Incus
     * agent reboots a new VM after it writes the seed, because cloud-init has started by then. Without those
     * templates, cloud-init reads the user-data from the agent's socket through its `LXD` data source, so it
     * must wait for the agent. The other templates, such as `/etc/hostname`, stay.
     */
    private function singleBoot(TaskVmHost $host, Node $node, string $name): void
    {
        $this->image->checked($name, 'exec cloud-init drop-in', $this->image->exec($host, $node, $name, [
            'install', '-D', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', self::CloudInitDropIn,
        ], self::CloudInitAfterAgent));

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

    private function clean(TaskVmHost $host, Node $node, string $name): void
    {
        $script = file_get_contents(resource_path(self::CleanScript));
        $result = $this->image->exec($host, $node, $name, ['bash', '-s'], is_string($script) ? $script : throw new TaskVmException('task_vm.setup_unavailable', 'The base image clean script is missing.'));
        if (! $result->succeeded() || trim($result->stdout) !== self::Confirmation) {
            throw IncusHost::failed("the base image build VM [{$name}]", 'exec base-image-clean.sh', $result);
        }
    }

    /** Stops the builder and publishes its disk under `$alias`. Returns the new fingerprint. */
    private function publish(TaskVmHost $host, Node $node, string $name, string $alias): string
    {
        $this->image->checked($name, 'stop', $this->incus->run($host, $node, ['stop', '--', $name]));
        $this->image->checked($name, 'publish', $this->incus->run($host, $node, ['publish', '--compression', 'none', '--alias', $alias, '--', $name]));

        return $this->incus->aliasTarget($host, $node, $alias)
            ?? throw IncusHost::invalid("the base image build VM [{$name}]", "the published image has no alias [{$alias}]");
    }
}
