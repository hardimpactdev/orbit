<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

final readonly class TaskVmsSettings
{
    /**
     * @param  list<string>  $operator_owned
     * @param  array{count:int,project_id:int,size:string}|null  $slots  the ready slots the host keeps
     */
    public function __construct(
        public string $cidr,
        public int $max_vms,
        public ?string $project = null,
        public ?string $network = null,
        public ?string $pool = null,
        public ?string $image_alias = null,
        public ?string $zfs_dataset = null,
        public ?int $cpus = null,
        public ?string $memory = null,
        public ?string $disk = null,
        public ?array $operator_owned = null,
        public ?array $slots = null,
        public ?bool $ci_only = null,
        public ?string $pool_size = null,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data): self
    {
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $integer = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            cidr: $string('cidr') ?? '', max_vms: $integer('max_vms') ?? 0,
            project: $string('project'), network: $string('network'), pool: $string('pool'),
            image_alias: $string('image_alias'), zfs_dataset: $string('zfs_dataset'), pool_size: $string('pool_size'),
            ci_only: is_bool($data['ci_only'] ?? null) ? $data['ci_only'] : null,
            cpus: $integer('cpus'), memory: $string('memory'), disk: $string('disk'),
            operator_owned: is_array($data['operator_owned'] ?? null) ? array_values(array_filter($data['operator_owned'], is_string(...))) : null,
            slots: is_array($data['slots'] ?? null) && is_int($data['slots']['count'] ?? null) && is_int($data['slots']['project_id'] ?? null) && is_string($data['slots']['size'] ?? null)
                ? ['count' => $data['slots']['count'], 'project_id' => $data['slots']['project_id'], 'size' => $data['slots']['size']] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null);
    }
}
