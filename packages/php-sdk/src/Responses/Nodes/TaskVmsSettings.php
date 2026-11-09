<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

final readonly class TaskVmsSettings
{
    /**
     * @param  list<string>  $operator_owned
     * @param  array{count:int,project_id:int,size:string}|null  $slots  the ready slots the host keeps
     * @param  array{cidr:string,task_places:int,ci_places:int,project?:string,network?:string}|null  $containers  the host's task container side
     */
    public function __construct(
        public string $cidr,
        public int $task_places,
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
        public ?string $pool_size = null,
        public int $ci_places = 0,
        public ?array $containers = null,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data): self
    {
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $integer = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            cidr: $string('cidr') ?? '', task_places: $integer('task_places') ?? 0,
            project: $string('project'), network: $string('network'), pool: $string('pool'),
            image_alias: $string('image_alias'), zfs_dataset: $string('zfs_dataset'), pool_size: $string('pool_size'),
            ci_places: $integer('ci_places') ?? 0,
            cpus: $integer('cpus'), memory: $string('memory'), disk: $string('disk'),
            operator_owned: is_array($data['operator_owned'] ?? null) ? array_values(array_filter($data['operator_owned'], is_string(...))) : null,
            slots: is_array($data['slots'] ?? null) && is_int($data['slots']['count'] ?? null) && is_int($data['slots']['project_id'] ?? null) && is_string($data['slots']['size'] ?? null)
                ? ['count' => $data['slots']['count'], 'project_id' => $data['slots']['project_id'], 'size' => $data['slots']['size']] : null,
            containers: self::containers($data['containers'] ?? null),
        );
    }

    /** @return array{cidr:string,task_places:int,ci_places:int,project?:string,network?:string}|null */
    private static function containers(mixed $data): ?array
    {
        if (! is_array($data) || ! is_string($data['cidr'] ?? null) || ! is_int($data['task_places'] ?? null) || ! is_int($data['ci_places'] ?? null)) {
            return null;
        }

        return [
            'cidr' => $data['cidr'], 'task_places' => $data['task_places'], 'ci_places' => $data['ci_places'],
            ...(is_string($data['project'] ?? null) ? ['project' => $data['project']] : []),
            ...(is_string($data['network'] ?? null) ? ['network' => $data['network']] : []),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null);
    }
}
