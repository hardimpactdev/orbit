<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyFindingData extends Data
{
    /** @param list<string> $current_versions
     * @param list<string> $advisory_ids */
    public function __construct(
        public string $id,
        public string $package,
        public string $kind,
        public array $current_versions,
        public ?string $target_version,
        public array $advisory_ids,
        public string $state,
        public DependencyReasonData $reason,
        public ?DependencyLinksData $links,
    ) {}
}
