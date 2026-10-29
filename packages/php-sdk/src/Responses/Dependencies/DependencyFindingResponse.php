<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyFindingResponse
{
    /** @param list<string> $currentVersions
     * @param list<string> $advisoryIds */
    public function __construct(
        public string $id,
        public string $package,
        public string $kind,
        public array $currentVersions,
        public ?string $targetVersion,
        public array $advisoryIds,
        public string $state,
        public DependencyReasonResponse $reason,
        public ?DependencyLinksResponse $links,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['id', 'package', 'kind', 'current_versions', 'target_version', 'advisory_ids', 'state', 'reason', 'links']);

        return new self(
            DependencyResponseValue::text($v['id']),
            DependencyResponseValue::text($v['package']),
            DependencyResponseValue::text($v['kind']),
            array_map(DependencyResponseValue::text(...), DependencyResponseValue::rows($v['current_versions'], 5_000)),
            $v['target_version'] === null ? null : DependencyResponseValue::text($v['target_version']),
            array_map(DependencyResponseValue::text(...), DependencyResponseValue::rows($v['advisory_ids'], 1_048_576)),
            DependencyResponseValue::text($v['state']),
            DependencyReasonResponse::fromArray($v['reason']),
            $v['links'] === null ? null : DependencyLinksResponse::fromArray($v['links']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'package' => $this->package,
            'kind' => $this->kind,
            'current_versions' => $this->currentVersions,
            'target_version' => $this->targetVersion,
            'advisory_ids' => $this->advisoryIds,
            'state' => $this->state,
            'reason' => $this->reason->toArray(),
            'links' => $this->links?->toArray(),
        ];
    }
}
