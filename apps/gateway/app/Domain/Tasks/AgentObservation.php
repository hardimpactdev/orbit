<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class AgentObservation
{
    /**
     * @param  list<AgentInputRequest>  $inputRequests
     * @param  list<array{id: string, kind: string, label: string, text: string, at: string}>  $entries
     * @param  bool  $delta  The entries are only those after the reader's cursor, not the whole transcript.
     */
    public function __construct(
        public ?AgentThreadState $state,
        public array $inputRequests = [],
        public array $entries = [],
        public ?int $tokens = null,
        public ?int $inputTokens = null,
        public ?int $cachedInputTokens = null,
        public ?int $outputTokens = null,
        public ?int $modelCalls = null,
        public ?int $peakContextTokens = null,
        public ?float $costUsd = null,
        public ?int $linesAdded = null,
        public ?int $linesDeleted = null,
        public ?string $error = null,
        public ?string $cursor = null,
        public ?string $turnId = null,
        public ?string $sessionUpdatedAt = null,
        public ?string $providerError = null,
        public bool $delta = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state?->value,
            'input_requests' => array_map(static fn (AgentInputRequest $request): array => $request->toArray(), $this->inputRequests),
            'entries' => $this->entries,
            'tokens' => $this->tokens,
            'input_tokens' => $this->inputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'output_tokens' => $this->outputTokens,
            'model_calls' => $this->modelCalls,
            'peak_context_tokens' => $this->peakContextTokens,
            'cost_usd' => $this->costUsd,
            'lines_added' => $this->linesAdded,
            'lines_deleted' => $this->linesDeleted,
            'error' => $this->error,
            'provider_error' => $this->providerError,
        ];
    }
}
