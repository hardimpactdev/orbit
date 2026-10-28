<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencySourceResponse
{
    public function __construct(
        public string $id,
        public string $path,
        public string $ecosystem,
        public string $packageManager,
        public DependencyAttemptResponse $lastAttempt,
        public ?string $sourceCommit,
        public ?string $observedAt,
        public string $freshness,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['id', 'path', 'ecosystem', 'package_manager', 'last_attempt', 'source_commit', 'observed_at', 'freshness']);

        return new self(
            DependencyResponseValue::text($v['id']),
            DependencyResponseValue::text($v['path']),
            DependencyResponseValue::text($v['ecosystem']),
            DependencyResponseValue::text($v['package_manager']),
            DependencyAttemptResponse::fromArray($v['last_attempt']),
            $v['source_commit'] === null ? null : DependencyResponseValue::text($v['source_commit']),
            $v['observed_at'] === null ? null : DependencyResponseValue::text($v['observed_at']),
            DependencyResponseValue::text($v['freshness']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'ecosystem' => $this->ecosystem,
            'package_manager' => $this->packageManager,
            'last_attempt' => $this->lastAttempt->toArray(),
            'source_commit' => $this->sourceCommit,
            'observed_at' => $this->observedAt,
            'freshness' => $this->freshness,
        ];
    }
}
