<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Operations;

/** One page of operation:list. Its rows carry no input, result or steps; read one with ShowOperationRequest for those. */
final readonly class OperationsResponse
{
    /** @param list<OperationResponse> $operations */
    public function __construct(
        public array $operations,
        public string $requestId,
        public ?string $nextCursor = null,
    ) {}

    /** @return array{operations: list<array<string, mixed>>, next_cursor: ?string, request_id: string} */
    public function toArray(): array
    {
        return [
            'operations' => array_map(
                static function (OperationResponse $operation): array {
                    $data = $operation->toArray();
                    unset($data['input'], $data['result'], $data['request_id']);

                    return $data;
                },
                $this->operations,
            ),
            'next_cursor' => $this->nextCursor,
            'request_id' => $this->requestId,
        ];
    }
}
