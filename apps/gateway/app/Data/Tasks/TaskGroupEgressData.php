<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Models\TaskGroupEgress;

/** One outside host of a task group, as the API returns it. A name that nothing connected to has a null port and protocol. */
final readonly class TaskGroupEgressData
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
    ) {}

    public static function fromModel(TaskGroupEgress $row): self
    {
        return new self(
            $row->destination,
            $row->kind,
            $row->port === 0 ? null : $row->port,
            $row->protocol === '' ? null : $row->protocol,
            $row->addresses,
            $row->sources,
            $row->flags,
            $row->vm_name,
            $row->first_seen_at->toIso8601String(),
        );
    }

    /** @return array{destination: string, kind: string, port: int|null, protocol: string|null, addresses: list<string>, sources: list<string>, flags: list<string>, vm: string, first_seen_at: string} */
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
        ];
    }
}
