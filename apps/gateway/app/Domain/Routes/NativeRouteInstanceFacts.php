<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Shared\ResourceOperationException;

/** One possible source inspection result for an actual prospective Instance. */
final readonly class NativeRouteInstanceFacts
{
    public function __construct(public int $instanceId, public int $nodeId, public string $phpVersion, public bool $laravel)
    {
        if ($instanceId < 1 || $nodeId < 1 || ! in_array($phpVersion, app(InstancePhpVersionCatalog::class)->versions(), true)) {
            throw new ResourceOperationException('route.source_facts_invalid', 'The prospective source facts require an actual Instance and a catalog PHP version.', 422);
        }
    }

    public function key(): string
    {
        return $this->phpVersion.':'.($this->laravel ? 'true' : 'false');
    }
}
