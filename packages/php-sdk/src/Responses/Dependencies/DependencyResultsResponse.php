<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyResultsResponse
{
    /** @param list<DependencySourceResponse> $sources */
    public function __construct(
        public int $projectId,
        public ?int $instanceId,
        public string $sourceRef,
        public ?string $instanceCommit,
        public string $availability,
        public ?string $readAt,
        public ?string $generatedAt,
        public ?string $sourceCommit,
        public ?DependencyAttemptResponse $lastAttempt,
        public array $sources,
        public ?DependencyReportResponse $report,
        public DependencyBatchPrResponse $batchPr,
        public string $requestId = '',
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['project_id', 'instance_id', 'source_ref', 'instance_commit', 'availability', 'read_at', 'generated_at', 'source_commit', 'last_attempt', 'sources', 'report', 'batch_pr']);

        return new self(
            DependencyResponseValue::integer($v['project_id']),
            $v['instance_id'] === null ? null : DependencyResponseValue::integer($v['instance_id']),
            DependencyResponseValue::text($v['source_ref']),
            $v['instance_commit'] === null ? null : DependencyResponseValue::text($v['instance_commit']),
            DependencyResponseValue::text($v['availability']),
            $v['read_at'] === null ? null : DependencyResponseValue::text($v['read_at']),
            $v['generated_at'] === null ? null : DependencyResponseValue::text($v['generated_at']),
            $v['source_commit'] === null ? null : DependencyResponseValue::text($v['source_commit']),
            $v['last_attempt'] === null ? null : DependencyAttemptResponse::fromArray($v['last_attempt']),
            array_map(DependencySourceResponse::fromArray(...), DependencyResponseValue::rows($v['sources'], 64)),
            $v['report'] === null ? null : DependencyReportResponse::fromArray($v['report']),
            DependencyBatchPrResponse::fromArray($v['batch_pr']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'instance_id' => $this->instanceId,
            'source_ref' => $this->sourceRef,
            'instance_commit' => $this->instanceCommit,
            'availability' => $this->availability,
            'read_at' => $this->readAt,
            'generated_at' => $this->generatedAt,
            'source_commit' => $this->sourceCommit,
            'last_attempt' => $this->lastAttempt?->toArray(),
            'sources' => array_map(static fn (DependencySourceResponse $item): array => $item->toArray(), $this->sources),
            'report' => $this->report?->toArray(),
            'batch_pr' => $this->batchPr->toArray(),
            'request_id' => $this->requestId,
        ];
    }
}
