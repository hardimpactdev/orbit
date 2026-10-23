<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\CaddyPackageProgram;
use App\Models\Node;

/**
 * The Caddy package step on every Node that runs Caddy: the pinned package, the reload kernel setting,
 * and the removal of the Cloudsmith apt source Orbit used to publish. It runs before the `caddy`
 * artifact, so a Caddyfile is never validated against a Caddy below the floor.
 */
final readonly class CaddyPackageFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(
        private CaddyFootprintArtifact $caddy,
        private AgentCaddyPackage $package,
    ) {}

    public function name(): string
    {
        return 'caddy-package';
    }

    public function applies(Node $node): bool
    {
        return $this->caddy->applies($node);
    }

    public function digest(Node $node): string
    {
        return SourceDigest::of(self::inputs());
    }

    /** @return list<string> */
    public static function inputs(): array
    {
        return [
            'app/Infrastructure/Nodes/CaddyPackageProgram.php',
            'app/Infrastructure/Nodes/AgentCaddyPackage.php',
            'app/Domain/Nodes/CaddyRelease.php',
        ];
    }

    public function apply(Node $node): bool
    {
        $result = $this->package->install($node);

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                'node.footprint_caddy_package_failed',
                'The Caddy package step failed: '.trim($result->stderr),
                502,
            );
        }

        return str_contains($result->stdout, CaddyPackageProgram::Changed);
    }
}
