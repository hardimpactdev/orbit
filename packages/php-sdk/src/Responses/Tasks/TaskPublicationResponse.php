<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/**
 * Where a task copy's group stands on its way to a pull request: `working`, `waiting_for_pr_text`, `waiting_for_release`, `publishing`, `refused`
 * or `published`, with the reviewer's pull request text on the done check.
 */
final readonly class TaskPublicationResponse
{
    public function __construct(
        public ?string $mode,
        public ?string $modeSource,
        public string $state,
        public ?int $checkId = null,
        public ?string $commit = null,
        public ?int $operationId = null,
        public ?string $errorCode = null,
        /** @var array{title: string, body: string, operation_id: int}|null */
        public ?array $prText = null,
    ) {}

    /** Null when the group has no task copy. */
    public static function fromGatewayData(mixed $data, string $requestId): ?self
    {
        if (! is_array($data)) {
            return null;
        }
        $fields = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $fields[$key] = $value;
            }
        }

        return new self(
            mode: TaskFields::nullableText($fields, 'mode'),
            modeSource: TaskFields::nullableText($fields, 'mode_source'),
            state: TaskFields::text($fields, 'state', 'task publication', $requestId),
            checkId: TaskFields::nullableInt($fields, 'check_id'),
            commit: TaskFields::nullableText($fields, 'commit'),
            operationId: TaskFields::nullableInt($fields, 'operation_id'),
            errorCode: TaskFields::nullableText($fields, 'error_code'),
            prText: self::prText($fields['pr_text'] ?? null),
        );
    }

    /** @return array{title: string, body: string, operation_id: int}|null */
    private static function prText(mixed $text): ?array
    {
        if (! is_array($text) || ! is_string($text['title'] ?? null) || ! is_string($text['body'] ?? null) || ! is_int($text['operation_id'] ?? null)) {
            return null;
        }

        return ['title' => $text['title'], 'body' => $text['body'], 'operation_id' => $text['operation_id']];
    }

    /** @return array{mode: string|null, mode_source: string|null, state: string, check_id: int|null, commit: string|null, operation_id: int|null, error_code: string|null, pr_text: array{title: string, body: string, operation_id: int}|null} */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'mode_source' => $this->modeSource, 'state' => $this->state, 'check_id' => $this->checkId,
            'commit' => $this->commit, 'operation_id' => $this->operationId, 'error_code' => $this->errorCode, 'pr_text' => $this->prText];
    }
}
