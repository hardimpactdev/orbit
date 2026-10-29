<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyBatchPrResponse
{
    public function __construct(
        public string $status,
        public ?string $checkedAt,
        public ?string $lastSuccessAt,
        public ?string $errorCode,
        public ?DependencyPullRequestResponse $value,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['status', 'checked_at', 'last_success_at', 'error_code', 'value']);

        return new self(
            DependencyResponseValue::text($v['status']),
            $v['checked_at'] === null ? null : DependencyResponseValue::text($v['checked_at']),
            $v['last_success_at'] === null ? null : DependencyResponseValue::text($v['last_success_at']),
            $v['error_code'] === null ? null : DependencyResponseValue::text($v['error_code']),
            $v['value'] === null ? null : DependencyPullRequestResponse::fromArray($v['value']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'checked_at' => $this->checkedAt,
            'last_success_at' => $this->lastSuccessAt,
            'error_code' => $this->errorCode,
            'value' => $this->value?->toArray(),
        ];
    }
}
