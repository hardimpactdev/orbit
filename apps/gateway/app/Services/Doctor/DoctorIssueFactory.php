<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Exceptions\DoctorIssueIdentityMismatch;

/**
 * Normalizes boundary arrays before creating the typed issue contract.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class DoctorIssueFactory
{
    public function __construct(
        private DoctorIssueIdentityResolver $identityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $issue
     */
    public function fromArray(array $issue): DoctorIssue
    {
        $family = is_string($issue['family'] ?? null) ? $issue['family'] : '';
        $node = is_string($issue['node'] ?? null) ? $issue['node'] : null;
        $key = is_string($issue['key'] ?? null) ? $issue['key'] : '';
        $kind = is_string($issue['kind'] ?? null)
            ? DriftKind::tryFrom($issue['kind']) ?? DriftKind::Unknown
            : DriftKind::Unknown;
        $code = $this->identityResolver->resolve($issue, $key);
        $definition = DoctorIssueCatalog::require($code);

        if ($definition->family !== $family) {
            throw DoctorIssueIdentityMismatch::forFamily($family, $code, $definition->family);
        }

        $restorable = DoctorIssueCatalog::isRestorable($code);

        /** @var array<string, mixed> $detail */
        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];

        return new DoctorIssue(
            entry: new DriftEntry(
                family: $family,
                key: $key,
                kind: $kind,
                summary: is_string($issue['summary'] ?? null) ? $issue['summary'] : '',
                detail: $detail,
            ),
            node: $node,
            code: $code,
            definition: $definition,
            restorable: $restorable,
        );
    }

    /**
     * Rebuild an untrusted client issue from observation fields only.
     *
     * @param  array<string, mixed>  $issue
     */
    public function fromClientArray(array $issue): DoctorIssue
    {
        return $this->fromArray($issue);
    }

    public function fromDriftEntry(
        DriftEntry $entry,
        ?string $node,
        ?string $code = null,
        ?array $detail = null,
    ): DoctorIssue {
        return $this->fromArray([
            'family' => $entry->family,
            'node' => $node,
            'key' => $entry->key,
            ...($code !== null ? ['code' => $code] : []),
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $detail ?? $entry->detail ?? [],
        ]);
    }
}
