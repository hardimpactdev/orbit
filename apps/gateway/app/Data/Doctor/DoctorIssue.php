<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Enums\DoctorIssueDisposition;
use App\Enums\DriftKind;

/**
 * The public Doctor issue contract keeps all documented fields explicit.
 *
 * @mago-expect lint:too-many-properties
 */
final readonly class DoctorIssue
{
    public string $family;

    public string $key;

    public DriftKind $kind;

    public string $summary;

    /** @var array<string, mixed> */
    public array $detail;

    public DoctorIssueDisposition $disposition;

    public ?string $restoreAction;

    public bool $restorable;

    public bool $adoptable;

    /**
     * Build the public issue from one observation and its catalog definition.
     */
    public function __construct(
        DriftEntry $entry,
        public ?string $node,
        public string $code,
        DoctorIssueDefinition $definition,
        bool $restorable,
    ) {
        $this->family = $entry->family;
        $this->key = $entry->key;
        $this->kind = $entry->kind;
        $this->summary = $entry->summary;
        $this->detail = $entry->detail ?? [];
        $this->disposition = $definition->disposition;
        $this->restoreAction = $restorable ? $definition->restoreAction : null;
        $this->restorable = $restorable;
        $this->adoptable = $definition->adoptable;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'node' => $this->node,
            'key' => $this->key,
            'code' => $this->code,
            'kind' => $this->kind->value,
            'summary' => $this->summary,
            'detail' => $this->detail === [] ? (object) [] : $this->detail,
            'disposition' => $this->disposition->value,
            'restore_action' => $this->restoreAction,
            'restorable' => $this->restorable,
            'adoptable' => $this->adoptable,
        ];
    }
}
