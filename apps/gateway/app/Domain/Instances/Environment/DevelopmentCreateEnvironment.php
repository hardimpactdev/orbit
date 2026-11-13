<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\CloneValkeyKeyspace;
use App\Domain\Instances\DevelopmentMail;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Project;
use App\Models\WebSocketAppTarget;

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
     * the default's APP_KEY instead: the cloned data is encrypted with it. A clone's own Valkey user (`$valkey`) replaces the
     * template's keys of its prefix; for Laravel it also sets the connection and Horizon key prefixes of CloneValkeyKeyspace.
     *
     * @return list<string> the environment keys
     */
    public function admit(Instance $instance, Project $project, ?DatabaseConnection $database = null, ?Instance $source = null, ?DatabaseConnectionTarget $valkey = null): array
    {
        $template = [];
        foreach ($project->environmentTemplate()->first()->entries ?? [] as $entry) {
            if (! $entry['removed']) {
                $template[$entry['key']] = $entry['value'];
            }
        }
        $stored = $this->store->values($instance);
        $owned = $this->owned($instance, $project, $database, $valkey);
        $values = [...array_diff_key([...$template, ...$stored], array_flip($owned['forget'])), ...$owned['values']];
        $values['APP_NAME'] ??= $project->name;
        $values['APP_ENV'] ??= 'development';
        $values['APP_DEBUG'] ??= 'true';
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

    /**
     * The key names a clone's workspace phase needs to keep its default's `.env` from the seed copy
     * (docs/reference/development-containers.md#the-environment-of-a-clone): `own`, the keys Orbit renders for the clone itself and
     * takes from the render; `drop`, the keys of every connection and Reverb app attached to the default, which the clone never
     * copies; `guard`, the default's credential keys, whose values the clone never copies under another name. Names only: no value leaves the Gateway here.
     * `hosts` maps the default's own Route domains to the clone's, so a copied value that points at the default's site points at
     * the clone's; Route domains are no secrets. A per-Project list of keys the clone never copies joins `drop`. An Instance with
     * T3 that moved in has no `$source`: it keeps its own file.
     *
     * @return array{own: list<string>, drop: list<string>, guard: list<string>, hosts: array{from: list<string>, to: string|null}}
     */
    public function cloneKeys(Instance $instance, ?Instance $source, ?DatabaseConnection $database = null, ?DatabaseConnectionTarget $valkey = null): array
    {
        $owned = $this->owned($instance, $instance->project, $database, $valkey);
        $projection = app(DatabaseConnectionEnvProjection::class);
        $drop = $guard = [];
        foreach ($source === null ? [] : DatabaseConnectionTarget::query()->with('databaseConnection')->where('instance_id', $source->id)->get() as $target) {
            // Laravel's `<PREFIX>_URL` overrides every other key of the connection.
            $drop = [...$drop, ...$projection->managedKeys($target->prefix), $projection->key($target->prefix, 'URL')];
            // A Redis database is a small index, never a credential: matching it would drop any key set to "0".
            $suffixes = $target->databaseConnection->driver === DatabaseDriver::Redis ? ['USERNAME', 'PASSWORD'] : ['DATABASE', 'USERNAME', 'PASSWORD'];
            foreach ($suffixes as $suffix) {
                $guard[] = $projection->key($target->prefix, $suffix);
            }
        }
        if ($source !== null && WebSocketAppTarget::query()->where('instance_id', $source->id)->exists()) {
            // The default's Reverb app stays the default's; the clone gets the template's keys.
            $drop = [...$drop, ...WebSocketAppEnvProjection::Keys];
            $guard[] = 'REVERB_APP_SECRET';
        }
        $names = static function (array $keys): array {
            $keys = array_values(array_unique($keys));
            sort($keys, SORT_STRING);

            return $keys;
        };

        $from = $source === null ? [] : $source->routes()->pluck('routes.domain')->filter(static fn (mixed $domain): bool => is_string($domain))->values()->all();

        return ['own' => $names([...array_keys($owned['values']), ...$owned['forget'], ...$this->mailKeys($instance)]),
            'drop' => $names($drop), 'guard' => $names($guard), 'hosts' => ['from' => $names($from), 'to' => $this->domain($instance)]];
    }

    /**
     * The values Orbit sets for the Instance itself and the keys it removes: its own database under DB, its own Valkey user under
     * the default's prefix with the Laravel key prefixes of CloneValkeyKeyspace, and APP_URL on its own Route domain.
     *
     * @return array{values: array<string, string>, forget: list<string>}
     */
    private function owned(Instance $instance, Project $project, ?DatabaseConnection $database, ?DatabaseConnectionTarget $valkey): array
    {
        $values = $forget = [];
        $projection = app(DatabaseConnectionEnvProjection::class);
        foreach (array_filter([$database === null ? null : [$database, 'DB'], $valkey === null ? null : [$valkey->databaseConnection, $valkey->prefix]]) as [$connection, $prefix]) {
            $projected = $projection->project($connection, $prefix, 'wireguard', $instance->node);
            $values = array_replace(array_diff_key($values, array_flip($projected['forget'])), $projected['values']);
            $forget = [...$forget, ...array_diff($projected['forget'], array_keys($projected['values']))];
        }
        if ($valkey !== null && $project->is_laravel === true) {
            $values = array_replace($values, CloneValkeyKeyspace::laravelKeys($instance, $valkey->prefix));
        }
        if ($project->is_laravel === true && $this->domain($instance) !== null) {
            // The Instance's own Route domain, never the template's literal URL of another Instance.
            $values['APP_URL'] = 'https://{{instance.domain}}';
        }

        return ['values' => $values, 'forget' => array_values(array_unique($forget))];
    }

    /** The file the run writes, rendered from the stored values. */
    public function content(Instance $instance): string
    {
        $instance->loadMissing(['node', 'project']);

        $values = $this->store->values($instance);
        if ($this->mailKeys($instance) !== []) {
            $values['MAIL_MAILER'] = 'smtp';
        }

        return $this->renderer->render($this->context($instance), $values, $this->capturedReferences($instance));
    }

    /** @return list<string> Owned only in the newly rendered file; stored values and the Project template stay unchanged. */
    public function mailKeys(Instance $instance): array
    {
        return ($this->store->values($instance)['MAIL_MAILER'] ?? null) === 'log'
            && app(DevelopmentMail::class)->forInstance($instance) !== null ? ['MAIL_MAILER'] : [];
    }

    /** @return array<string, string> Provenance is used only while creating the initial development files. */
    private function capturedReferences(Instance $instance): array
    {
        $references = [];
        foreach ($instance->project->environmentTemplate()->first()->entries ?? [] as $entry) {
            if (! $entry['removed'] && isset($entry['reference'])) {
                $references[$entry['key']] = $entry['reference'];
            }
        }

        return $references;
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

        return $plan === null ? null : $this->renderer->render($this->context($instance), $plan->values, $this->capturedReferences($instance));
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
