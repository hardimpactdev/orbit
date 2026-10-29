<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyAttemptResponse
{
    public function __construct(
        public string $runId,
        public string $startedAt,
        public ?string $finishedAt,
        public string $status,
        public ?string $errorCode,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['run_id', 'started_at', 'finished_at', 'status', 'error_code']);

        return new self(
            DependencyResponseValue::text($v['run_id']),
            DependencyResponseValue::text($v['started_at']),
            $v['finished_at'] === null ? null : DependencyResponseValue::text($v['finished_at']),
            DependencyResponseValue::text($v['status']),
            $v['error_code'] === null ? null : DependencyResponseValue::text($v['error_code']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'status' => $this->status,
            'error_code' => $this->errorCode,
        ];
    }
}
