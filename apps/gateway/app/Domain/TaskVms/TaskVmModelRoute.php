<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The model route of containers ([Model forwarder](/reference/ai-router#container-model-forwarder)): the router guard
 * reached through the Node model forwarder and the fixed placeholder key each container holds, even before router setup.
 * The extension that runs the model router binds it. The key changes only on an incident.
 */
interface TaskVmModelRoute
{
    /** Refuses while the route has no guard, for example before the ai-router setup (`ai_router.disabled`, 409). */
    public function assertReady(): void;

    /** The guard origin that the Node model forwarder calls, such as `http://10.44.0.3:8318`. */
    public function origin(): string;

    /** The fixed placeholder key; only the guard accepts it. It is there even while the route is not ready, for redaction. */
    public function key(): string;
}
