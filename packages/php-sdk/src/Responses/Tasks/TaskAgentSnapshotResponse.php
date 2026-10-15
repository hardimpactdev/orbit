<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One redacted snapshot of an agent thread's conversation, live from its Node or from the saved transcript. */
final readonly class TaskAgentSnapshotResponse
{
    /**
     * @param  list<array{id: string, kind: string, details: array<string, mixed>}>  $inputRequests
     * @param  list<array{id: string, kind: string, label: string, text: string, at: string}>  $entries
     */
    public function __construct(
        public int $threadId,
        public string $kind,
        public ?string $state,
        public array $inputRequests,
        public array $entries,
        public ?int $tokens,
        public ?int $inputTokens,
        public ?int $cachedInputTokens,
        public ?int $outputTokens,
        public ?int $modelCalls,
        public ?int $peakContextTokens,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?string $error,
        public ?string $providerError,
        public ?string $cursor,
        public bool $archived,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        if (! is_bool($data['archived'] ?? null)) {
            throw TaskFields::invalid('agent snapshot', $requestId);
        }

        return new self(
            threadId: TaskFields::id($data, 'thread_id', 'agent snapshot', $requestId),
            kind: TaskFields::text($data, 'kind', 'agent snapshot', $requestId),
            state: TaskFields::nullableText($data, 'state'),
            inputRequests: self::inputRequests($data, $requestId),
            entries: self::entries($data, $requestId),
            tokens: TaskFields::nullableInt($data, 'tokens'),
            inputTokens: TaskFields::nullableInt($data, 'input_tokens'),
            cachedInputTokens: TaskFields::nullableInt($data, 'cached_input_tokens'),
            outputTokens: TaskFields::nullableInt($data, 'output_tokens'),
            modelCalls: TaskFields::nullableInt($data, 'model_calls'),
            peakContextTokens: TaskFields::nullableInt($data, 'peak_context_tokens'),
            linesAdded: TaskFields::nullableInt($data, 'lines_added'),
            linesDeleted: TaskFields::nullableInt($data, 'lines_deleted'),
            error: TaskFields::nullableText($data, 'error'),
            providerError: TaskFields::nullableText($data, 'provider_error'),
            cursor: TaskFields::nullableText($data, 'cursor'),
            archived: $data['archived'],
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'thread_id' => $this->threadId,
            'kind' => $this->kind,
            'state' => $this->state,
            'input_requests' => $this->inputRequests,
            'entries' => $this->entries,
            'tokens' => $this->tokens,
            'input_tokens' => $this->inputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'output_tokens' => $this->outputTokens,
            'model_calls' => $this->modelCalls,
            'peak_context_tokens' => $this->peakContextTokens,
            'lines_added' => $this->linesAdded,
            'lines_deleted' => $this->linesDeleted,
            'error' => $this->error,
            'provider_error' => $this->providerError,
            'cursor' => $this->cursor,
            'archived' => $this->archived,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{id: string, kind: string, label: string, text: string, at: string}>
     */
    private static function entries(array $data, string $requestId): array
    {
        $entries = [];

        foreach (self::objects($data['entries'] ?? [], $requestId) as $entry) {
            $entries[] = [
                'id' => TaskFields::text($entry, 'id', 'agent snapshot', $requestId),
                'kind' => TaskFields::text($entry, 'kind', 'agent snapshot', $requestId),
                'label' => TaskFields::text($entry, 'label', 'agent snapshot', $requestId),
                'text' => TaskFields::text($entry, 'text', 'agent snapshot', $requestId),
                'at' => TaskFields::text($entry, 'at', 'agent snapshot', $requestId),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{id: string, kind: string, details: array<string, mixed>}>
     */
    private static function inputRequests(array $data, string $requestId): array
    {
        $requests = [];

        foreach (self::objects($data['input_requests'] ?? [], $requestId) as $request) {
            $details = $request['details'] ?? [];

            $requests[] = [
                'id' => TaskFields::text($request, 'id', 'agent snapshot', $requestId),
                'kind' => TaskFields::text($request, 'kind', 'agent snapshot', $requestId),
                // An empty object decodes as an empty list.
                'details' => $details === [] ? [] : self::object($details, $requestId),
            ];
        }

        return $requests;
    }

    /** @return list<array<string, mixed>> */
    private static function objects(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw TaskFields::invalid('agent snapshot', $requestId);
        }

        return array_map(static fn (mixed $item): array => self::object($item, $requestId), $value);
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $requestId): array
    {
        if (! is_array($value)) {
            throw TaskFields::invalid('agent snapshot', $requestId);
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw TaskFields::invalid('agent snapshot', $requestId);
            }

            $object[$key] = $item;
        }

        return $object;
    }
}
