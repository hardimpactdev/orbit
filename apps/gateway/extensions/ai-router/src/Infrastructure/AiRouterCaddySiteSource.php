<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\ContributedCaddySiteSource;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

/**
 * The collector site on the Node that the enabled AiRouter extension names. The provider contributes it to the
 * Caddy site source plug-in point.
 */
final readonly class AiRouterCaddySiteSource implements ContributedCaddySiteSource
{
    /** The collector's slug in CaddySiteCertificates. */
    public const string CertificateSlug = 'ai-router';

    public function __construct(
        private AiRouterState $state,
        private AiRouterCaddySiteRenderer $renderer = new AiRouterCaddySiteRenderer,
        private CaddySiteCertificates $certificates = new CaddySiteCertificates,
    ) {}

    public function order(): int
    {
        return 70;
    }

    public function certificateSlug(): string
    {
        return self::CertificateSlug;
    }

    public function sites(Node $node): array
    {
        if (
            ! $this->state->enabled()
            || $this->state->nodeId() !== $node->id
            || ! $this->certificates->published($node->id, self::CertificateSlug)
        ) {
            return [];
        }

        return [new CaddySite(
            source: 'ai-router',
            name: AiRouterFootprint::Hostname,
            listener: CaddyListenerRule::Shared,
            hosts: [AiRouterFootprint::Hostname],
            port: 443,
            body: $this->renderer->render(),
            bindPlaceholder: AiRouterFootprint::CaddyBindPlaceholder,
        )];
    }
}
