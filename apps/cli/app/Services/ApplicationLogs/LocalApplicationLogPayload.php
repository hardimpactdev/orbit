<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class LocalApplicationLogPayload
{
    public const string LogicalPath = 'storage/logs/laravel.log';

    private function __construct(
        public string $absolutePath,
        public string $authorizedRoot,
        public int $lines,
        public bool $follow,
        public ?LocalApplicationLogOperationStream $operationStream = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload): self
    {
        return new self(
            absolutePath: ApplicationLogPayloadFields::absolutePath($payload['absolute_path'] ?? null),
            authorizedRoot: ApplicationLogPayloadFields::authorizedRoot($payload['authorized_root'] ?? null),
            lines: ApplicationLogPayloadFields::lines($payload['lines'] ?? null),
            follow: ApplicationLogPayloadFields::follow($payload['follow'] ?? false),
            operationStream: LocalApplicationLogOperationStream::from($payload['operation_stream'] ?? null),
        );
    }
}
