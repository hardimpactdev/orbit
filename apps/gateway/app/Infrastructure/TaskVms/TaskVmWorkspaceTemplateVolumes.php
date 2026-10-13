<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Models\Node;

/**
 * Reads and writes the custom volumes of a task VM host's project and pool: workspace templates, claim clones, and
 * their `user.orbit.*` config ({@see TaskVmWorkspaceTemplate}).
 */
final readonly class TaskVmWorkspaceTemplateVolumes
{
    public function __construct(private IncusHost $incus) {}

    /**
     * Every custom volume of the host's project, by name, with the template state its config records.
     *
     * @return array<string, TaskVmWorkspaceTemplate>
     */
    public function read(TaskVmHost $host, Node $hostNode): array
    {
        $subject = "the storage volumes of Node [{$hostNode->name}]";
        $result = $this->incus->run($host, $hostNode, ['storage', 'volume', 'list', '--format', 'json', '--', $host->pool], timeout: 60.0);
        $list = IncusHost::json($subject, $result->succeeded() ? $result : throw IncusHost::failed($subject, 'storage volume list', $result));
        if (! array_is_list($list)) {
            throw IncusHost::invalid($subject, 'the volume list is not a JSON list');
        }
        $volumes = [];
        foreach ($list as $volume) {
            if (! is_array($volume) || ! is_string($volume['name'] ?? null) || ! is_string($volume['type'] ?? null)) {
                throw IncusHost::invalid($subject, 'a volume has no name or type');
            }
            if ($volume['type'] !== 'custom') {
                continue;
            }
            $config = is_array($volume['config'] ?? null) ? $volume['config'] : [];
            $volumes[$volume['name']] = TaskVmWorkspaceTemplate::fromVolume($volume['name'], array_filter($config, is_string(...)));
        }

        return $volumes;
    }

    /** Writes the config of a finished template, which makes it ready ({@see TaskVmWorkspaceTemplate::metadata()}). */
    public function publish(TaskVmHost $host, Node $hostNode, TaskVmWorkspaceTemplate $template): void
    {
        $result = $this->incus->run($host, $hostNode, ['storage', 'volume', 'set', '--', $host->pool, $template->name, ...$template->metadata()]);
        if (! $result->succeeded()) {
            throw IncusHost::failed("the workspace template [{$template->name}]", 'storage volume set', $result);
        }
    }

    /** Deletes a volume of the host's pool. An absent volume counts as deleted. */
    public function delete(TaskVmHost $host, Node $hostNode, string $name): void
    {
        $result = $this->incus->run($host, $hostNode, ['storage', 'volume', 'delete', '--', $host->pool, $name], timeout: 120.0);
        if (! $result->succeeded() && array_key_exists($name, $this->read($host, $hostNode))) {
            throw IncusHost::failed("the storage volume [{$name}]", 'storage volume delete', $result);
        }
    }
}
