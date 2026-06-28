<?php

declare(strict_types=1);

namespace App\Services\Solo;

final readonly class SoloUpstreamResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public bool $ok,
        public array $data,
        public array $meta,
        public ?SoloUpstreamError $error,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function success(array $data, array $meta = []): self
    {
        return new self(
            ok: true,
            data: $data,
            meta: $meta,
            error: null,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function failure(string $code, string $message, array $meta = [], int $status = 422): self
    {
        return new self(
            ok: false,
            data: [],
            meta: $meta,
            error: new SoloUpstreamError(
                code: $code,
                message: $message,
                meta: $meta,
                status: $status,
            ),
        );
    }
}
