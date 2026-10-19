<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Models\Node;
use App\Models\Route;

interface AiRouterPublicationManager
{
    /**
     * Publishes the collector site, certificate, and DNS record. A `$takeover` Route that already serves the
     * collector hostname is withdrawn in the same Caddy reload and then removed.
     */
    public function converge(Node $node, ?Route $takeover = null): void;

    public function remove(Node $node): void;
}
