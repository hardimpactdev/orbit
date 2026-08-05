<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\ApplicationLogs;

use Saloon\Http\Response;

final readonly class ApplicationLogStreamResponse
{
    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $operation,
        public array $data,
    ) {}

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        /** @var array<string, mixed> $data */
        $data = [];

        if (is_array($body) && isset($body['success']['data']) && is_array($body['success']['data'])) {
            foreach ($body['success']['data'] as $key => $item) {
                if (is_string($key)) {
                    $data[$key] = $item;
                }
            }
        }

        /** @var array<string, mixed> $operation */
        $operation = [];
        $rawOperation = $data['operation'] ?? null;

        if (is_array($rawOperation)) {
            foreach ($rawOperation as $key => $item) {
                if (is_string($key)) {
                    $operation[$key] = $item;
                }
            }
        }

        return new self(operation: $operation, data: $data);
    }
}
