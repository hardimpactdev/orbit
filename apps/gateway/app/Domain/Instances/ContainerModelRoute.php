<?php

declare(strict_types=1);

namespace App\Domain\Instances;

/**
 * The guarded model route for Instance containers and their fixed placeholder key. The model-router extension binds it.
 * Containers reach the guard through their Node's forwarder; the real provider credentials stay outside the container.
 */
interface ContainerModelRoute
{
    /** Refuses while the route has no guard, for example before the ai-router setup (`ai_router.disabled`, 409). */
    public function assertReady(): void;

    /** The upstream guard origin, such as `http://10.44.0.3:8318`. */
    public function origin(): string;

    /** The fixed placeholder key; only the guard accepts it. It is there even while the route is not ready, for redaction. */
    public function key(): string;
}
