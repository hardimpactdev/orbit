<?php

declare(strict_types=1);

namespace App\Services\Gateway;

readonly class GatewayResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private bool $success,
        private array $data,
        private array $meta,
        private ?string $errorCode,
        private ?string $errorMessage,
        private array $errorMeta,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function success(array $data = [], array $meta = []): self
    {
        return new self(success: true, data: $data, meta: $meta, errorCode: null, errorMessage: null, errorMeta: []);
    }

    /**
     * @param  array<string, mixed>  $errorMeta
     */
    public static function error(?string $code = null, ?string $message = null, array $errorMeta = []): self
    {
        return new self(success: false, data: [], meta: [], errorCode: $code, errorMessage: $message, errorMeta: $errorMeta);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorMeta(): array
    {
        return $this->errorMeta;
    }
}
