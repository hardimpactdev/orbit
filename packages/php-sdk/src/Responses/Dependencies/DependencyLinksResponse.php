<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyLinksResponse
{
    public function __construct(
        public int $groupId,
        public string $runId,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['group_id', 'run_id']);

        return new self(
            DependencyResponseValue::integer($v['group_id']),
            DependencyResponseValue::text($v['run_id']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'run_id' => $this->runId,
        ];
    }
}
