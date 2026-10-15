<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliState;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Models\Node;

/**
 * The collector site on the Node that the enabled ProxyCli extension names. The provider contributes it to the
 * Caddy site source plug-in point.
 */
final readonly class ProxyCliCaddySiteSource implements NodeCaddySiteSource
{
    /** The collector's slug in CaddySiteCertificates. */
    public const string CertificateSlug = 'proxycli';

    public function __construct(
        private ProxyCliState $state,
        private ProxyCliCaddySiteRenderer $renderer = new ProxyCliCaddySiteRenderer,
        private CaddySiteCertificates $certificates = new CaddySiteCertificates,
    ) {}

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
            source: 'proxycli',
            name: ProxyCliFootprint::Hostname,
            listener: CaddyListenerRule::Shared,
            hosts: [ProxyCliFootprint::Hostname],
            port: 443,
            body: $this->renderer->render(ProxyCliProcess::PORT),
            bindPlaceholder: ProxyCliFootprint::CaddyBindPlaceholder,
        )];
    }
}
