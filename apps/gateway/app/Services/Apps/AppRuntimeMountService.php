<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Instance;
use App\Models\InstanceRuntimeMount;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Support\Collection;

/**
 * @mago-expect lint:kan-defect
 */
final readonly class AppRuntimeMountService
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    public function mountsForRuntime(App $app, ?Instance $instance = null): array
    {
        if (! $this->isSupportedAppDevPhpApp($app, $instance)) {
            return [];
        }

        if (! $instance instanceof Instance) {
            return [];
        }

        $instance->loadMissing('runtimeMounts');

        $mounts = $instance
            ->runtimeMounts
            ->map($this->instanceMountPayload(...))
            ->values()
            ->all();

        /** @var list<array{source: string, target: string, read_only: bool}> $mounts */
        return $mounts;
    }

    /**
     * @return array{action: string, mount: InstanceRuntimeMount, mounts: Collection<int, InstanceRuntimeMount>}
     */
    public function addToInstance(Instance $instance, string $source, string $target, bool $readOnly = true): array
    {
        $instance->loadMissing('app.node');
        $app = $instance->app;

        [$source, $target] = $this->validateIntent($app, $source, $target, $instance);

        $mount = $instance->runtimeMounts()->firstOrNew(['target' => $target]);
        $exists = $mount->exists;

        $mount->source = $source;
        $mount->target = $target;
        $mount->read_only = $readOnly;

        $changed = ! $exists || $mount->isDirty(['source', 'target', 'read_only']);
        $mount->save();

        $instance->unsetRelation('runtimeMounts');

        $action = 'created';

        if ($exists) {
            $action = $changed ? 'updated' : 'unchanged';
        }

        return [
            'action' => $action,
            'mount' => $mount->refresh(),
            'mounts' => $this->listForInstance($instance),
        ];
    }

    /**
     * @return array{action: string, mount: InstanceRuntimeMount|null, mounts: Collection<int, InstanceRuntimeMount>}
     */
    public function removeFromInstance(Instance $instance, string $target): array
    {
        $target = $this->normalizePath($target, 'target');

        $mount = $instance
            ->runtimeMounts()
            ->where('target', $target)
            ->first();

        if (! $mount instanceof InstanceRuntimeMount) {
            return [
                'action' => 'missing',
                'mount' => null,
                'mounts' => $this->listForInstance($instance),
            ];
        }

        $mount->delete();
        $instance->unsetRelation('runtimeMounts');

        return [
            'action' => 'removed',
            'mount' => $mount,
            'mounts' => $this->listForInstance($instance),
        ];
    }

    /**
     * @return Collection<int, InstanceRuntimeMount>
     */
    public function listForInstance(Instance $instance): Collection
    {
        $mounts = [];

        foreach (InstanceRuntimeMount::query()
            ->where('instance_id', $instance->id)
            ->orderBy('target')
            ->get() as $mount) {
            if ($mount instanceof InstanceRuntimeMount) {
                $mounts[] = $mount;
            }
        }

        return new Collection($mounts);
    }

    /**
     * @return array{source: string, target: string, read_only: bool}
     */
    public function instanceMountPayload(InstanceRuntimeMount $mount): array
    {
        return [
            'source' => $mount->source,
            'target' => $mount->target,
            'read_only' => $mount->read_only,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function validateIntent(App $app, string $source, string $target, ?Instance $instance = null): array
    {
        $this->assertSupportedApp($app, $instance);

        $source = $this->normalizePath($source, 'source');
        $target = $this->normalizePath($target, 'target');

        $home = $this->homeForValidation($app, $instance);

        if (! $this->isSameOrChild($source, $home)) {
            throw $this->validationFailure(
                'source_outside_app_dev_home',
                "Runtime mount sources must live under {$home}.",
                ['source' => $source, 'home' => $home],
            );
        }

        if ($source === $home || $this->isSensitiveHomeSource($source, $home)) {
            throw $this->validationFailure(
                'source_sensitive',
                'Runtime mount sources cannot expose sensitive home directories or credential files.',
                ['source' => $source],
            );
        }

        if ($this->isReservedTarget($app, $target)) {
            throw $this->validationFailure(
                'target_reserved',
                "Runtime mount target '{$target}' is reserved by Orbit.",
                ['target' => $target],
            );
        }

        return [$source, $target];
    }

    private function homeForValidation(App $app, ?Instance $instance = null): string
    {
        if ($instance instanceof Instance) {
            $node = $this->placement->nodeForInstance($instance);

            if ($node instanceof Node) {
                return NodeHostPaths::homeDirectoryFor($node->platform, $node->user);
            }
        }

        $app->loadMissing('node');

        if ($app->node instanceof Node) {
            return NodeHostPaths::homeDirectoryFor($app->node->platform, $app->node->user);
        }

        return NodeHostPaths::homeDirectoryFor(null, $this->nodeUser($app));
    }

    private function assertSupportedApp(App $app, ?Instance $instance): void
    {
        $selector = $instance instanceof Instance ? "{$app->name}.{$instance->name}" : $app->name;
        $runtime = $app->runtimeKind();

        if ($runtime !== AppRuntimeKind::Php) {
            throw $this->validationFailure(
                'instance_runtime_not_php',
                "Instance '{$selector}' does not use the PHP runtime.",
                ['app' => $app->name, 'instance' => $instance?->name, 'runtime' => $runtime->value],
            );
        }

        if (! $this->isAppDevApp($app, $instance)) {
            throw $this->validationFailure(
                'instance_mounts_app_dev_only',
                'Configurable instance runtime mounts are currently supported on app-dev nodes only.',
                ['app' => $app->name, 'instance' => $instance?->name],
            );
        }
    }

    private function isSupportedAppDevPhpApp(App $app, ?Instance $instance = null): bool
    {
        return $app->runtimeKind() === AppRuntimeKind::Php && $this->isAppDevApp($app, $instance);
    }

    private function isAppDevApp(App $app, ?Instance $instance = null): bool
    {
        $node = $this->placement->runtimeNode($app, $instance);

        return $node instanceof Node && $node->hasActiveRole(NodeRoleName::AppDevelopment->value);
    }

    private function nodeUser(App $app): string
    {
        $app->loadMissing('node');

        $nodeUser = trim($app->node?->user ?: 'orbit');

        if ($nodeUser === '' || preg_match('/^[A-Za-z0-9._-]+$/', $nodeUser) !== 1) {
            throw $this->validationFailure(
                'node_user_invalid',
                "App '{$app->name}' has an invalid runtime user.",
                ['app' => $app->name],
            );
        }

        return $nodeUser;
    }

    private function normalizePath(string $path, string $field): string
    {
        $path = trim($path);

        if ($path === '') {
            throw $this->validationFailure(
                "{$field}_required",
                ucfirst($field).' path is required.',
                ['field' => $field],
            );
        }

        if (! str_starts_with($path, '/')) {
            throw $this->validationFailure(
                "{$field}_must_be_absolute",
                ucfirst($field).' path must be absolute.',
                ['field' => $field, $field => $path],
            );
        }

        if (preg_match('/[\x00\r\n]/', $path) === 1) {
            throw $this->validationFailure(
                "{$field}_invalid",
                ucfirst($field).' path contains invalid characters.',
                ['field' => $field],
            );
        }

        $segments = explode('/', $path);

        if (in_array('..', $segments, true)) {
            throw $this->validationFailure(
                "{$field}_contains_parent_segment",
                ucfirst($field).' path cannot contain parent directory segments.',
                ['field' => $field, $field => $path],
            );
        }

        while (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        return $path;
    }

    private function isSensitiveHomeSource(string $source, string $home): bool
    {
        $sensitivePaths = [
            "{$home}/.aws",
            "{$home}/.config",
            "{$home}/.composer/auth.json",
            "{$home}/.gnupg",
            "{$home}/.netrc",
            "{$home}/.npmrc",
            "{$home}/.ssh",
        ];

        return array_any(
            $sensitivePaths,
            fn (string $sensitivePath): bool => $this->isSameOrChild($source, $sensitivePath),
        );
    }

    private function isReservedTarget(App $app, string $target): bool
    {
        $reservedTargets = [
            AppRuntimeContainer::SourceTarget,
            AppRuntimeContainer::PhpIniMountTarget,
            AppDevelopmentPackagesMount::Target,
            AppRuntimeContainerRenderer::XdgRoot,
            '/config',
            '/data',
        ];

        $appPath = rtrim($app->path, '/');

        if ($appPath !== '') {
            $reservedTargets[] = $appPath;
        }

        return array_any(
            $reservedTargets,
            fn (string $reservedTarget): bool => $this->isSameOrChild($target, $reservedTarget),
        );
    }

    private function isSameOrChild(string $path, string $parent): bool
    {
        $parent = rtrim($parent, '/');

        return $path === $parent || str_starts_with($path, "{$parent}/");
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailure(
        string $reason,
        string $message,
        array $meta = [],
    ): AppRuntimeMountValidationException {
        return new AppRuntimeMountValidationException(
            reason: $reason,
            message: $message,
            meta: [
                'reason' => $reason,
                ...$meta,
            ],
        );
    }
}
