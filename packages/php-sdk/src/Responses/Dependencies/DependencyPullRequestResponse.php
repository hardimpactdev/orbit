<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyPullRequestResponse
{
    public function __construct(
        public string $repository,
        public int $number,
        public string $url,
        public string $headCommit,
        public int $groupId,
        public string $runId,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['repository', 'number', 'url', 'head_commit', 'group_id', 'run_id']);

        return new self(
            DependencyResponseValue::text($v['repository']),
            DependencyResponseValue::integer($v['number']),
            DependencyResponseValue::text($v['url']),
            DependencyResponseValue::text($v['head_commit']),
            DependencyResponseValue::integer($v['group_id']),
            DependencyResponseValue::text($v['run_id']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'repository' => $this->repository,
            'number' => $this->number,
            'url' => $this->url,
            'head_commit' => $this->headCommit,
            'group_id' => $this->groupId,
            'run_id' => $this->runId,
        ];
    }
}
