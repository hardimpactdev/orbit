<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\CaddyRelease;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\ContributedCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use Illuminate\Container\Attributes\Tag;

/** The Node's Caddyfile, published like `orbit:caddy-build`: a graceful reload only when it changed. */
final readonly class CaddyFootprintArtifact implements NodeFootprintArtifact
{
    public const string RenderRefused = 'caddy_render_refused';

    public const string ValidateRefused = 'caddy_validate_refused';

    /** @param iterable<ContributedCaddySiteSource> $contributions */
    public function __construct(
        private NodeCaddyfileRenderer $renderer,

        #[Tag(ContributedCaddySiteSource::class)] private iterable $contributions,
    ) {}

    public function name(): string
    {
        return 'caddy';
    }

    public function applies(Node $node): bool
    {
        return $node->platform === 'linux'
            && (CaddySiteRoles::nodeExpectsCaddy($node->id) || $this->renderer->render($node)->sites !== []);
    }

    /**
     * The version of the Caddy build code, not the rendered Caddyfile: a user's site or Route never makes a
     * Node drift. Site changes publish themselves, and Doctor's `role.caddy_build_drift` reports live drift.
     */
    public function digest(Node $node): string
    {
        return SourceDigest::of($this->inputs());
    }

    /**
     * Every Gateway source file the Caddy build renders from: the build, its site sources, the sources that
     * extensions contribute, and the classes they use, such as `DevelopmentSite` and the `CaddyRelease` pin.
     *
     * @return list<string>
     */
    public function inputs(): array
    {
        return SourceClosure::of(['app/Infrastructure/Caddy'], [CaddyRelease::class, ...array_map(
            static fn (ContributedCaddySiteSource $source): string => $source::class,
            array_values([...$this->contributions]),
        )]);
    }
}
