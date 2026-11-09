<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\WireGuard\Ipv4Subnet;

/**
 * The task container side of a host, its `containers` member (docs/reference/node-settings.md#task-container-fields):
 * its own places, Incus project, bridge and bridge network beside the host's task VMs.
 */
final readonly class TaskVmContainers
{
    /** The stock image alias of the container project, which host preparation copies and the base starts from. */
    public const string SourceImage = 'ubuntu-26.04-ct';

    private const array Keys = ['task_places', 'ci_places', 'project', 'network', 'cidr'];

    public function __construct(
        public int $taskPlaces,
        public int $ciPlaces,
        public string $project,
        public string $network,
        public string $cidr,
    ) {}

    /**
     * Validates the `containers` member at `$key` over `task_vms.incus.host_defaults.containers`, with the rules of the
     * VM fields. The host checks that it collides with none of its VM resources.
     *
     * @param  array<string, mixed>  $defaults
     *
     * @throws TaskVmException
     */
    public static function fromConfig(mixed $entry, string $key, Ipv4Subnet $vpnSubnet, array $defaults): self
    {
        $shape = "{$key} must be an object with task_places, ci_places and cidr, and optionally project and network.";
        TaskVmConfig::validate(['entry' => $entry], ['entry' => ['array:'.implode(',', self::Keys)]], ['entry' => $shape]);
        $entry = [...$defaults, ...(is_array($entry) ? $entry : [])];
        $places = ['required', 'integer:strict', 'between:0,64'];
        $valid = TaskVmConfig::validate($entry, [
            'task_places' => $places,
            'ci_places' => $places,
            'project' => ['required', 'string', 'regex:'.TaskVmHost::ProjectPattern],
            'network' => ['required', 'string', 'regex:'.TaskVmHost::NetworkPattern],
            'cidr' => ['required', TaskVmConfig::bridgeRule("{$key}.cidr", $vpnSubnet)],
        ], [
            'task_places' => "{$key}.task_places must be an integer from 0 to 64.",
            'ci_places' => "{$key}.ci_places must be an integer from 0 to 64.",
            'project' => "{$key}.project is invalid.",
            'network' => "{$key}.network is invalid.",
            'cidr.required' => "{$key}.cidr must be an IPv4 network, for example 10.251.78.0/24.",
        ]);

        return new self(
            taskPlaces: $valid->integer('task_places'),
            ciPlaces: $valid->integer('ci_places'),
            project: $valid->string('project')->value(),
            network: $valid->string('network')->value(),
            cidr: Ipv4Subnet::from($valid->string('cidr')->value())->value(),
        );
    }
}
