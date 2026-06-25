<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;
use Closure;
use Illuminate\Support\Collection;

final readonly class FleetProbeScope
{
    /**
     * @param  Collection<int, Node>  $targets
     * @param  list<string>  $families
     * @param  (Closure(Node, 'running'|'done', ?array<string, mixed>=): void)|null  $onNodeProgress
     */
    public function __construct(
        public Collection $targets,
        public array $families,
        public ?string $key,
        public ?Closure $onNodeProgress,
    ) {}
}
