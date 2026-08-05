<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;

final readonly class RuntimeHibernationScope
{
    public function __construct(
        public string $type,
        public int $id,
        public Node $node,
        public ProcessOwnerContext $context,
    ) {}

    public function key(): string
    {
        return "{$this->type}-{$this->id}";
    }

    public function lockKey(): string
    {
        return "runtime-hibernation:{$this->key()}";
    }

    public function activationFenceKey(): string
    {
        return "runtime-activation-fence:{$this->key()}";
    }

    public function dependencyFenceKey(): string
    {
        $path = $this->sourcePath();

        if ($path === null) {
            return "runtime-dependency-fence:{$this->key()}";
        }

        return "runtime-dependency-fence:node-{$this->node->id}:".hash('sha256', $path);
    }

    public function activationFenceSeconds(): int
    {
        $runningTimeout = (int) config(
            'orbit.runtime_hibernation.activation_running_timeout_seconds',
            default: 1200,
        );
        $configuredFence = (int) config(
            'orbit.runtime_hibernation.activation_fence_seconds',
            default: 1260,
        );

        return max($configuredFence, $runningTimeout + 60);
    }

    public function sourcePath(): ?string
    {
        if ($this->context->workspace instanceof Workspace) {
            return $this->normalizedPath($this->context->workspace->path);
        }

        $config = $this->context->instance?->driver_config;

        if ($config instanceof OrbitInstanceDriverConfigData) {
            return $this->normalizedPath($config->path);
        }

        return null;
    }

    public function displayName(): string
    {
        $config = $this->context->instance?->driver_config;

        if ($this->context->workspace instanceof Workspace) {
            $domain = $config instanceof OrbitInstanceDriverConfigData ? $config->domain : null;

            return is_string($domain) && $domain !== ''
                ? "{$this->context->workspace->name}.{$domain}"
                : $this->context->workspace->name;
        }

        if (
            $this->context->instance instanceof Instance
            && $config instanceof OrbitInstanceDriverConfigData
            && is_string($config->domain)
            && $config->domain !== ''
        ) {
            return $config->domain;
        }

        return $this->context->app instanceof App ? $this->context->app->name : 'application';
    }

    private function normalizedPath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || ! str_starts_with($path, '/')) {
            return null;
        }

        return rtrim($path, characters: '/');
    }
}
