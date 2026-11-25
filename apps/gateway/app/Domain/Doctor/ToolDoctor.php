<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ObservedPackages;
use App\Domain\Tools\ToolDefinitionResolver;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolPolicy;
use App\Domain\Tools\VersionConstraint;
use App\Models\Tool;
use UnexpectedValueException;

final readonly class ToolDoctor
{
    private const int FactSeconds = 720;

    public function __construct(private ToolManagerRegistry $managers, private ToolPolicy $policy,
        private ToolDefinitionResolver $definitions, private VersionConstraint $constraints) {}

    public function inspect(DoctorFamilyInspection $check): DoctorFamilyReportData
    {
        if (! $check->managed) {
            return $check->report();
        }
        $facts = [];
        $unavailable = ToolDoctorIssueCode::NotInstalled->value;
        $mismatch = ToolDoctorIssueCode::VersionMismatch->value;
        foreach (Tool::query()->where('node_id', $check->node->id)->with(['manager', 'aptRepository'])->orderBy('id')->get()->reject->withdrawn() as $tool) {
            $check->read($tool->id, [$unavailable, $mismatch], function () use ($check, $tool, &$facts, $unavailable, $mismatch): void {
                $manager = $this->managers->find($tool->manager->name) ?? throw new UnexpectedValueException;
                $name = $manager->name();
                try {
                    $definition = $this->definitions->resolve('update', $manager, $tool->package, $tool, null, null, null, $this->policy->active());
                } catch (ResourceOperationException $error) {
                    throw new UnexpectedValueException(previous: $error);
                }
                if ($name === ToolManagerName::ConnServer) {
                    $version = $tool->installed_version ?? throw new UnexpectedValueException;
                } else {
                    if (! array_key_exists($name->value, $facts)) {
                        $facts[$name->value] = ObservedPackages::find($check->node, $name);
                    }
                    $fact = $facts[$name->value];
                    if ($fact === null || $fact->observedAt->lessThan(now()->subSeconds(self::FactSeconds))) {
                        throw new UnexpectedValueException;
                    }
                    $package = $fact->packages[$tool->package] ?? null;
                    $version = $package === null ? null : ($package->version() ?? throw new UnexpectedValueException);
                }
                if ($version === null) {
                    $check->issue($unavailable, $tool->id, $tool->package, 'The managed Tool is not installed.', 'installed', 'absent');
                } else {
                    $normalized = $manager->normalizeVersion($version);
                    if (($definition->version !== null && ! $definition->matches($name, $version))
                        || ($definition->constraint !== null && ($normalized === null || ! $this->constraints->allows($normalized, $definition->constraint)))) {
                        $check->issue($mismatch, $tool->id, $tool->package, 'The installed Tool does not match its desired coordinate or constraint.',
                            $definition->version ?? $definition->constraint, $version);
                    }
                }
                $check->observed($tool->id, $unavailable, $mismatch);
            });
        }

        return $check->report();
    }
}
