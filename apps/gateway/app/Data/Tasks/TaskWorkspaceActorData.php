<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskWorkspaceActorData extends Data
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

    /** @param array{id: int, kind: string, owner: string, acquired_at?: ?string, queued_at?: string, thread_id: ?int, check_id: ?int, remote_uncertain: bool} $actor */
    public static function fromLease(array $actor): self
    {
        return new self($actor['id'], $actor['kind'], $actor['owner'], $actor['acquired_at'] ?? null, $actor['queued_at'] ?? null, $actor['thread_id'], $actor['check_id'], $actor['remote_uncertain']);
    }
}
