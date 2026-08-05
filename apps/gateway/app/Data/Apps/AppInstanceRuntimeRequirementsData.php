<?php

declare(strict_types=1);

namespace App\Data\Apps;

use Spatie\LaravelData\Data;

/**
 * Migration-only helper for immutable pre-2026-08-05 migrations that seed
 * `runtime_requirements` JSON on `app_instances`:
 * - `2026_06_17_201539_create_app_instances_table`
 * - `2026_07_12_084244_canonicalize_app_instance_ownership`
 *
 * Do not inject this class into runtime models, controllers, factories, or
 * services. Active runtime uses {@see InstanceRuntimeRequirementsData} only.
 * This is not a compatibility alias of the current class.
 */
final class AppInstanceRuntimeRequirementsData extends Data
{
    /**
     * @param  list<string>  $php_extensions
     */
    public function __construct(
        public array $php_extensions = [],
    ) {}

    /**
     * @return list<string>
     */
    public function normalizedPhpExtensions(): array
    {
        $extensions = array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            array_filter($this->php_extensions, is_string(...)),
        );

        $extensions = array_values(array_unique(array_filter($extensions)));
        sort($extensions);

        return $extensions;
    }
}
