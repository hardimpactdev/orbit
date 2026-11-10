<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Models\DatabaseConnection;
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
     * A retry keeps the values the first admission stored, including its APP_KEY. A clone of a container default (`$source`) takes
     * the default's APP_KEY instead: the cloned data is encrypted with it.
     *
     * @return list<string> the environment keys
     */
    public function admit(Instance $instance, Project $project, ?DatabaseConnection $database = null, ?Instance $source = null): array
    {
        $template = [];
        foreach ($project->environmentTemplate()->first()->entries ?? [] as $entry) {
            if (! $entry['removed']) {
                $template[$entry['key']] = $entry['value'];
            }
        }
        $values = [...$template, ...$this->store->values($instance)];
        if ($database !== null) {
            // The Instance's own database copy, attached under DB, replaces the template's database keys.
            $projected = app(DatabaseConnectionEnvProjection::class)->project($database, 'DB', 'wireguard', $instance->node);
            $values = [...array_diff_key($values, array_flip($projected['forget'])), ...$projected['values']];
        }
        $values['APP_NAME'] ??= $project->name;
        $values['APP_ENV'] ??= 'development';
        $values['APP_DEBUG'] ??= 'true';
        if ($project->is_laravel === true && $this->domain($instance) !== null) {
            // The Instance's own Route domain, never the template's literal URL of another Instance.
            $values['APP_URL'] = 'https://{{instance.domain}}';
        }
        if ($source instanceof Instance && ($values['APP_KEY'] ?? '') === '') {
            $values['APP_KEY'] = $this->store->values($source)['APP_KEY'] ?? '';
        }
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

        return $this->renderer->render($this->context($instance), $this->store->values($instance));
    }

    private function context(Instance $instance): InstanceEnvironmentContext
    {
        return new InstanceEnvironmentContext(
            instanceId: $instance->id, projectId: $instance->project_id, nodeId: $instance->node_id, environment: 'development',
            path: $this->directory($instance), executionUser: $instance->node->user, laravel: $instance->project->is_laravel === true,
            routeId: null, routeDomain: $this->domain($instance), nodeStatus: $instance->node->status->value, node: $instance->node,
        );
    }

    /** `.env.testing` for an owned SQL database with a test database, rendered as `env:sync` renders it; else null. */
    public function testing(Instance $instance): ?string
    {
        $instance->loadMissing(['node', 'project']);
        $plan = app(InstanceTestEnvironment::class)->plan($instance->id, $this->store->values($instance));

        return $plan === null ? null : $this->renderer->render($this->context($instance), $plan->values);
    }

    /** The domain of the Instance's own Route, which the create reserved before admission. */
    private function domain(Instance $instance): ?string
    {
        $domain = $instance->routes()->whereNull('web_root')->value('domain');

        return is_string($domain) ? $domain : null;
    }

    public function directory(Instance $instance): string
    {
        return ApplicationDirectory::resolve($instance->checkout_path, $instance->root ?? $instance->project->root);
    }
}
