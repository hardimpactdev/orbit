<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;

/**
 * The name a container Instance's machine presents (docs/reference/development-containers.md#the-machine-name): never its Incus name
 * `instance-<id>`, which stays the machine key. The presented name is `<instance name>.<project slug>` exactly as stored; stock T3
 * reads it from `PRETTY_HOSTNAME` in `/etc/machine-info` as its environment label. The kernel hostname is the same text when that is a
 * valid hostname of at most 63 characters. Otherwise it is exactly 64 characters, the kernel's limit, and carries the Instance id,
 * so it can equal no shorter name and no other Instance's: the hostname stays unique on every Node.
 */
final readonly class InstanceMachineName
{
    /** The kernel's HOST_NAME_MAX. */
    public const int HostnameLimit = 64;

    /** The longest project part of a 64-character hostname. */
    public const int ProjectPartLimit = 31;

    /** @return array{hostname: string, pretty: string} */
    public static function intent(Instance $instance): array
    {
        $project = $instance->project;

        return ['hostname' => self::hostname($instance->id, $instance->name, $project->id, $project->slug),
            'pretty' => self::presented($instance->name, $project->slug)];
    }

    /**
     * A clone's `/etc/machine-info`, written before its first start so T3's first start reads the label; the copy's own Incus templates
     * leave the file alone.
     *
     * @return array{path: string, content: string, uid: int, gid: int, mode: string}
     */
    public static function bootFile(Instance $instance): array
    {
        return ['path' => '/etc/machine-info', 'content' => self::machineInfo(self::intent($instance)['pretty']), 'uid' => 0, 'gid' => 0, 'mode' => '0644'];
    }

    /** The whole text of `/etc/machine-info`, Orbit's file; tests/Fixtures/machine-name-files.json ties it to instance_machine_name.py. */
    public static function machineInfo(string $pretty): string
    {
        return 'PRETTY_HOSTNAME='.$pretty."\n";
    }

    /**
     * The name of every container that the Node's runtimes record, for the converge's file write
     * (docs/reference/development-containers.md#existing-containers).
     *
     * @return list<array{project: string, container: string, hostname: string, pretty: string}>
     */
    public static function forNode(Node $node): array
    {
        return array_values(InstanceRuntime::query()->where('node_id', $node->id)->whereHas('instance')->with('instance.project')->orderBy('container_name')->get()
            ->map(static fn (InstanceRuntime $runtime): array => ['project' => $runtime->incus_project, 'container' => $runtime->container_name,
                ...self::intent($runtime->instance)])->all());
    }

    public static function presented(string $instance, string $slug): string
    {
        return $instance.'.'.$slug;
    }

    /** The Instance name is a lowercase DNS label (InstanceName); a Project slug may hold uppercase letters, `_` or edge hyphens. */
    public static function hostname(int $instanceId, string $instance, int $projectId, string $slug): string
    {
        $label = self::label($slug, $projectId);
        $presented = self::presented($instance, $label);
        if ($label === $slug && strlen($presented) < self::HostnameLimit) {
            return $presented;
        }
        $project = rtrim(substr($label, 0, self::ProjectPartLimit), '-');
        $instancePart = self::HostnameLimit - 1 - strlen($project);
        $id = (string) $instanceId;
        $prefix = substr($instance, 0, min(strlen($instance), $instancePart - 1 - strlen($id)));

        // The id ends the first label after its last hyphen; zeros pad the name to exactly 64 characters.
        return $prefix.'-'.str_pad($id, $instancePart - strlen($prefix) - 1, '0', STR_PAD_LEFT).'.'.$project;
    }

    /** A slug as a hostname label: lowercase, `_` as `-`, no edge hyphen; a slug of only `_` and `-` names its Project by id. */
    private static function label(string $slug, int $projectId): string
    {
        $label = trim(strtolower(str_replace('_', '-', $slug)), '-');

        return $label === '' ? 'project-'.$projectId : $label;
    }
}
