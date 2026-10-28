<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyReasonResponse
{
    public function __construct(
        public string $code,
        public string $text,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['code', 'text']);

        return new self(
            DependencyResponseValue::text($v['code']),
            DependencyResponseValue::text($v['text']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'text' => $this->text,
        ];
    }
}
