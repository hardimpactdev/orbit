<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskThreadObservation
{
    /** @param list<AgentInputRequest> $inputRequests
     */
    public function __construct(
        public int $threadId,
        public TaskThreadRole $role,
        public string $sessState,
        public bool $idle,
        public ?string $pendingApprovalId,
        public ?string $pendingUserInputId,
        public bool $hasNewCommitsSinceThreadStart,
        public ?string $prUrl,
        public ?string $ciSummary,
        public bool $available = true,
        public ?string $error = null,
        public array $inputRequests = [],
        public ?string $turnId = null,
        public ?string $sessionUpdatedAt = null,
        public ?string $providerError = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'thread_id' => $this->threadId,
            'role' => $this->role->value,
            'state' => $this->sessState,
            'available' => $this->available,
            'error' => $this->error,
            'provider_error' => $this->providerError,
            'input_requests' => array_map(static fn (AgentInputRequest $request): array => $request->toArray(), $this->inputRequests),
            'idle' => $this->idle,
            'pending_approval_id' => $this->pendingApprovalId,
            'pending_user_input_id' => $this->pendingUserInputId,
            'has_new_commits_since_thread_start' => $this->hasNewCommitsSinceThreadStart,
            'pr_url' => $this->prUrl,
            'ci_summary' => $this->ciSummary,
        ];
    }
}
