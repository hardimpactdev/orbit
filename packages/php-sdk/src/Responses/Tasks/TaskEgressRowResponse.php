<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One outside host of a task group. A name that nothing connected to has a null port and protocol. */
final readonly class TaskEgressRowResponse
{
    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $sources
     * @param  list<string>  $flags
     */
    public function __construct(
        public string $destination,
        public string $kind,
        public ?int $port,
        public ?string $protocol,
        public array $addresses,
        public array $sources,
        public array $flags,
        public string $vm,
        public string $firstSeenAt,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            destination: TaskFields::text($data, 'destination', 'task egress row', $requestId),
            kind: TaskFields::text($data, 'kind', 'task egress row', $requestId),
            port: TaskFields::nullableInt($data, 'port'),
            protocol: TaskFields::nullableText($data, 'protocol'),
            addresses: self::strings($data, 'addresses', $requestId),
            sources: self::strings($data, 'sources', $requestId),
            flags: self::strings($data, 'flags', $requestId),
            vm: TaskFields::text($data, 'vm', 'task egress row', $requestId),
            firstSeenAt: TaskFields::text($data, 'first_seen_at', 'task egress row', $requestId),
            requestId: $requestId,
        );
    }

    /**
     * @return array{destination: string, kind: string, port: int|null, protocol: string|null, addresses: list<string>, sources: list<string>, flags: list<string>, vm: string, first_seen_at: string, request_id: string}
     */
    public function toArray(): array
    {
        return [
            'destination' => $this->destination,
            'kind' => $this->kind,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'addresses' => $this->addresses,
            'sources' => $this->sources,
            'flags' => $this->flags,
            'vm' => $this->vm,
            'first_seen_at' => $this->firstSeenAt,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function strings(array $data, string $key, string $requestId): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value) || array_filter($value, static fn (mixed $item): bool => ! is_string($item)) !== []) {
            throw TaskFields::invalid('task egress row', $requestId);
        }

        /** @var list<string> $value */
        return $value;
    }
}
