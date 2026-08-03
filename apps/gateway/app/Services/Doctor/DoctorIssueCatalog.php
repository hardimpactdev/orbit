<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssueDefinition;
use App\Enums\DoctorIssueDisposition;
use App\Exceptions\DoctorUncataloguedIssueException;
use App\Services\Doctor\IssueCatalog\DoctorIssueDefinitionProvider;

/**
 * Aggregates family-owned Doctor issue definitions.
 *
 * Unknown codes fail closed via require(); callers must not invent dispositions.
 */
final class DoctorIssueCatalog
{
    /**
     * @return list<DoctorIssueDefinitionProvider>
     */
    public static function providers(): array
    {
        return DoctorIssueCatalogBuilder::providers();
    }

    /**
     * @return array<string, DoctorIssueDefinition>
     */
    public static function definitions(): array
    {
        /** @var array<string, DoctorIssueDefinition>|null $cache */
        static $cache = null;

        if (is_array($cache)) {
            return $cache;
        }

        $cache = DoctorIssueCatalogBuilder::build();

        return $cache;
    }

    public static function definition(string $code): ?DoctorIssueDefinition
    {
        return self::definitions()[$code] ?? null;
    }

    public static function require(string $code): DoctorIssueDefinition
    {
        $definition = self::definition($code);

        if (! $definition instanceof DoctorIssueDefinition) {
            throw DoctorUncataloguedIssueException::forCode($code);
        }

        return $definition;
    }

    public static function has(string $code): bool
    {
        return array_key_exists($code, self::definitions());
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }

    public static function isRestorable(string $code): bool
    {
        $definition = self::definition($code);

        return (
            $definition instanceof DoctorIssueDefinition
            && $definition->disposition === DoctorIssueDisposition::GenuineDrift
            && DoctorRestoreSupport::supports($code)
            && is_string($definition->restoreAction)
            && $definition->restoreAction !== ''
            && $definition->restoreAction === DoctorRestoreSupport::actionId($code)
        );
    }
}
