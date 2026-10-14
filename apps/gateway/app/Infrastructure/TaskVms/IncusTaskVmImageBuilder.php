<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Boot preparation, cleaning, and publishing helpers for the base image chain's individual steps. */
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
        private TaskVmImageRecipe $recipe,
    ) {}

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
