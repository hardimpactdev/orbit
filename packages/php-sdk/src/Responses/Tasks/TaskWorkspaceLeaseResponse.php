<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskWorkspaceLeaseResponse
{
    /** @param list<TaskWorkspaceActorResponse> $waiters */
    public function __construct(
        public int $instanceId,
        public string $instanceName,
        public int $groupId,
        public string $projectCode,
        public ?TaskWorkspaceActorResponse $holder,
        public array $waiters,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $holder = $data['holder'] ?? null;
        $waiters = $data['waiters'] ?? null;
        if (($holder !== null && ! is_array($holder)) || ! is_array($waiters) || ! array_is_list($waiters)) {
            throw TaskFields::invalid('workspace lease', $requestId);
        }
        $actors = [];
        foreach ($waiters as $waiter) {
            if (! is_array($waiter)) {
                throw TaskFields::invalid('workspace lease', $requestId);
            }
            $actors[] = self::actor($waiter, $requestId);
        }

        return new self(
            instanceId: TaskFields::id($data, 'instance_id', 'workspace lease', $requestId),
            instanceName: TaskFields::text($data, 'instance_name', 'workspace lease', $requestId),
            groupId: TaskFields::id($data, 'group_id', 'workspace lease', $requestId),
            projectCode: TaskFields::text($data, 'project_code', 'workspace lease', $requestId),
            holder: $holder === null ? null : self::actor($holder, $requestId),
            waiters: $actors,
        );
    }

    /** @param array<array-key, mixed> $value */
    private static function actor(array $value, string $requestId): TaskWorkspaceActorResponse
    {
        $data = [];
        foreach ($value as $key => $field) {
            if (! is_string($key)) {
                throw TaskFields::invalid('workspace lease actor', $requestId);
            }
            $data[$key] = $field;
        }

        return TaskWorkspaceActorResponse::fromGatewayData($data, $requestId);
    }

    public function reference(): string
    {
        return $this->projectCode === '' ? '#'.$this->groupId : $this->projectCode.'-'.$this->groupId;
    }

    /** @return array{instance_id: int, instance_name: string, group_id: int, project_code: string,
     * holder: array{id: int, kind: string, owner: string, acquired_at: ?string, queued_at: ?string, thread_id: ?int, check_id: ?int}|null,
     * waiters: list<array{id: int, kind: string, owner: string, acquired_at: ?string, queued_at: ?string, thread_id: ?int, check_id: ?int}>}
     */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'instance_name' => $this->instanceName,
            'group_id' => $this->groupId,
            'project_code' => $this->projectCode,
            'holder' => $this->holder?->toArray(),
            'waiters' => array_map(static fn (TaskWorkspaceActorResponse $actor): array => $actor->toArray(), $this->waiters),
        ];
    }
}
