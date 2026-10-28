<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

use Orbit\Sdk\Support\DependencyResponseValue;

final readonly class DependencyPackageResponse
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public bool $direct,
        public array $scopes,
        public string $origin,
    ) {}

    public static function fromArray(#[\SensitiveParameter] mixed $data): self
    {
        $v = DependencyResponseValue::fields($data, ['id', 'name', 'version', 'direct', 'scopes', 'origin']);

        return new self(
            DependencyResponseValue::text($v['id']),
            DependencyResponseValue::text($v['name']),
            DependencyResponseValue::text($v['version']),
            DependencyResponseValue::boolean($v['direct']),
            array_map(DependencyResponseValue::text(...), DependencyResponseValue::rows($v['scopes'], 2)),
            DependencyResponseValue::text($v['origin']),

        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'direct' => $this->direct,
            'scopes' => $this->scopes,
            'origin' => $this->origin,
        ];
    }
}
