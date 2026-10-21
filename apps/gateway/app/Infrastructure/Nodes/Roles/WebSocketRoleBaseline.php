<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketPublicationManager;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Domain\WebSocket\WebSocketRuntimeLifecycle;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

final readonly class WebSocketRoleBaseline implements RoleBaseline
{
    public function __construct(
        private WebSocketRuntimeLifecycle $runtime,
        private WebSocketPublicationManager $publication,
        private WebSocketCredentialManager $credentials,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        // Refuse before changing a running Reverb.
        $this->publication->checkListenAddresses($node);
        $credentials = $this->credentials->ensure($node);
        $runtimeConverged = false;

        try {
            $this->runtime->converge($node, $assignment, $credentials);
            $runtimeConverged = true;
            $this->publication->converge($node);
        } catch (Throwable $exception) {
            try {
                // Existing assignments may serve every app, also while retrying a failed publication.
                if ($runtimeConverged && $assignment->wasRecentlyCreated) {
                    $this->runtime->remove($node, $assignment, false);
                }
            } catch (Throwable $rollback) {
                // A lost Node lock refuses the rollback's commands too; the loss is the failure to report.
                if (NodeLockLoss::in($exception)) {
                    throw $exception;
                }

                throw new ResourceOperationException(
                    'websocket.rollback_failed',
                    'WebSocket convergence rollback failed.',
                    502,
                    new ResourceOperationException(
                        'websocket.convergence_failed',
                        $exception->getMessage(),
                        502,
                        $rollback,
                    ),
                );
            }

            throw $exception;
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        // Until the site is withdrawn and Reverb has stopped, the Gateway keeps publishing to this Node's
        // Reverb, so a removal that fails part way never strands the clients still connected there.
        $this->publication->remove($node);
        $this->runtime->remove($node, $assignment, $purgeData);
        $this->publication->retire($node);
        $this->credentials->purge($node, runtimeStopped: true);
    }

    /**
     * Removes only what lives on the Gateway, for a websocket node Orbit
     * cannot reach after operator confirmation: private DNS, credentials, and required runtime membership. The
     * node's own Caddy site, certificate, Reverb checkout and firewall rule
     * stay on the box, since reaching them would require SSH to a node that
     * is unreachable.
     */
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->publication->removeUnreachable($node);
        $this->credentials->purge($node);
        app(WebSocketRegistry::class)->abandonNode($node->id);
    }
}
