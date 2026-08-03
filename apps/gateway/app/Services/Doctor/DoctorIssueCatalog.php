<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssueDefinition;
use App\Enums\DoctorIssueDisposition;
use App\Exceptions\DoctorUncataloguedIssueException;
use App\Services\Doctor\IssueCatalog\AppDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\DatabaseConnectionDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\DoctorIssueDefinitionProvider;
use App\Services\Doctor\IssueCatalog\FirewallRuleDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\InstanceDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\NodeDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\ProcessDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\ProxyDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\ScheduleDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\ToolDoctorIssueDefinitions;
use App\Services\Doctor\IssueCatalog\WorkspaceDoctorIssueDefinitions;

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
        return [
            new NodeDoctorIssueDefinitions,
            new AppDoctorIssueDefinitions,
            new InstanceDoctorIssueDefinitions,
            new ToolDoctorIssueDefinitions,
            new ProcessDoctorIssueDefinitions,
            new ProxyDoctorIssueDefinitions,
            new WorkspaceDoctorIssueDefinitions,
            new ScheduleDoctorIssueDefinitions,
            new FirewallRuleDoctorIssueDefinitions,
            new DatabaseConnectionDoctorIssueDefinitions,
        ];
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

        $definitions = self::buildDefinitions();
        $cache = $definitions;

        return $definitions;
    }

    /**
     * @return array<string, DoctorIssueDefinition>
     */
    private static function buildDefinitions(): array
    {
        /** @var array<string, DoctorIssueDefinition> $definitions */
        $definitions = [];

        foreach (self::providers() as $provider) {
            foreach ($provider->definitions() as $definition) {
                self::assertUniqueDefinition($definitions, $definition);
                self::assertGenuineDefinitionIsDispatchable($definition);
                $definitions[$definition->code] = $definition;
            }
        }

        ksort($definitions);

        return $definitions;
    }

    /**
     * @param  array<string, DoctorIssueDefinition>  $definitions
     */
    private static function assertUniqueDefinition(array $definitions, DoctorIssueDefinition $definition): void
    {
        if (array_key_exists($definition->code, $definitions)) {
            throw new \LogicException(
                "Duplicate Doctor issue definition for '{$definition->code}'.",
            );
        }
    }

    private static function assertGenuineDefinitionIsDispatchable(DoctorIssueDefinition $definition): void
    {
        if ($definition->disposition !== DoctorIssueDisposition::GenuineDrift) {
            return;
        }

        if (! is_string($definition->restoreAction) || $definition->restoreAction === '') {
            throw new \LogicException(
                "Genuine drift '{$definition->code}' is missing a restore action.",
            );
        }

        if (! DoctorRestoreSupport::supports($definition->code)) {
            throw new \LogicException(
                "Genuine drift '{$definition->code}' is not registered in DoctorRestoreSupport.",
            );
        }

        if ($definition->restoreAction !== DoctorRestoreSupport::actionId($definition->code)) {
            throw new \LogicException(
                "Genuine drift '{$definition->code}' restore_action must match DoctorRestoreSupport.",
            );
        }
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
