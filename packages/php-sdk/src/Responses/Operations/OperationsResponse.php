<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Operations;

final readonly class OperationsResponse
{
    /** @param list<OperationResponse> $operations */
    public function __construct(
        public array $operations,
        public string $requestId,
    ) {}

    /** @return array{operations: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'operations' => array_map(
                static function (OperationResponse $operation): array {
                    $data = $operation->toArray(withSteps: false);
                    unset($data['request_id']);

                    return $data;
                },
                $this->operations,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
