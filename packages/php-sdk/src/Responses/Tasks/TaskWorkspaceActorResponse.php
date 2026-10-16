<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskWorkspaceActorResponse
{
    public function __construct(
        public int $id,
        public string $kind,
        public string $owner,
        public ?string $acquiredAt,
        public ?string $queuedAt,
        public ?int $threadId,
        public ?int $checkId,
        public bool $remoteUncertain,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        foreach (['acquired_at', 'queued_at'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw TaskFields::invalid('workspace lease actor', $requestId);
            }
        }
        foreach (['thread_id', 'check_id'] as $key) {
            if (isset($data[$key]) && (! is_int($data[$key]) || $data[$key] < 1)) {
                throw TaskFields::invalid('workspace lease actor', $requestId);
            }
        }

        if (isset($data['remote_uncertain']) && ! is_bool($data['remote_uncertain'])) {
            throw TaskFields::invalid('workspace lease actor', $requestId);
        }

        return new self(
            id: TaskFields::id($data, 'id', 'workspace lease actor', $requestId),
            kind: TaskFields::text($data, 'kind', 'workspace lease actor', $requestId),
            owner: TaskFields::text($data, 'owner', 'workspace lease actor', $requestId),
            acquiredAt: TaskFields::nullableText($data, 'acquired_at'),
            queuedAt: TaskFields::nullableText($data, 'queued_at'),
            threadId: TaskFields::nullableInt($data, 'thread_id'),
            checkId: TaskFields::nullableInt($data, 'check_id'),
            remoteUncertain: ($data['remote_uncertain'] ?? false) === true,
        );
    }

    /** @return array{id: int, kind: string, owner: string, acquired_at: ?string, queued_at: ?string, thread_id: ?int, check_id: ?int, remote_uncertain: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'owner' => $this->owner,
            'acquired_at' => $this->acquiredAt,
            'queued_at' => $this->queuedAt,
            'thread_id' => $this->threadId,
            'check_id' => $this->checkId,
            'remote_uncertain' => $this->remoteUncertain,
        ];
    }
}
