<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyReportData extends Data
{
    /** @param list<DependencyPackageData> $packages
     * @param list<DependencyFindingData> $findings */
    public function __construct(
        public string $source_commit,
        public string $publication_id,
        public string $path,
        public string $ecosystem,
        public string $package_manager,
        public string $observed_at,
        public string $run_id,
        public string $basis,
        public string $observed_on,
        public ?int $instance_id,
        public string $freshness,
        public array $packages,
        public int $package_count,
        public int $packages_page,
        public array $findings,
        public int $finding_count,
        public int $findings_page,
    ) {}
}
