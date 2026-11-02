<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\SourceControl\ApplicationDirectory;
use App\Models\Instance;
use App\Models\Project;

/**
 * The `.env` of a development create, rendered by the Gateway. Admission stores the values; the run only writes the
 * file. Nothing is read back from the Node (Recall orbit-gateway-renders-create-env).
 */
final readonly class DevelopmentCreateEnvironment
{
    public function __construct(private InstanceEnvironmentRenderer $renderer, private InstanceEnvironmentStore $store) {}

    /**
     * The Project's environment template, then the Instance's stored values, then Orbit's defaults and, for Laravel, a new APP_KEY.
     * A retry keeps the values the first admission stored, including its APP_KEY.
     *
     * @return list<string> the environment keys
     */
    public function admit(Instance $instance, Project $project): array
    {
        $template = [];
        foreach ($project->environmentTemplate()->first()->entries ?? [] as $entry) {
            if (! $entry['removed']) {
                $template[$entry['key']] = $entry['value'];
            }
        }
        $values = [...$template, ...$this->store->values($instance)];
        $values['APP_NAME'] ??= $project->name;
        $values['APP_ENV'] ??= 'development';
        $values['APP_DEBUG'] ??= 'true';
        if ($project->is_laravel === true && ($values['APP_KEY'] ?? '') === '') {
            $values['APP_KEY'] = LaravelApplicationKey::generate();
        }
        $this->store->seedForCreate($instance, $values);
        $keys = array_keys($values);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** The file the run writes, rendered from the stored values. */
    public function content(Instance $instance): string
    {
        $instance->loadMissing(['node', 'project']);

        return $this->renderer->render(new InstanceEnvironmentContext(
            instanceId: $instance->id, projectId: $instance->project_id, nodeId: $instance->node_id, environment: 'development',
            path: $this->directory($instance), executionUser: $instance->node->user, laravel: $instance->project->is_laravel === true,
            routeId: null, routeDomain: null, nodeStatus: $instance->node->status->value, node: $instance->node,
        ), $this->store->values($instance));
    }

    public function directory(Instance $instance): string
    {
        return ApplicationDirectory::resolve($instance->checkout_path, $instance->root ?? $instance->project->root);
    }
}
