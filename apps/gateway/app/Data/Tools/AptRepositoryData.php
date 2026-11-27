<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Models\AptRepository;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AptRepositoryData extends Data
{
    /** @param array<string, mixed> $definition the definition without its name */
    public function __construct(
        public int $id,
        public int $nodeId,
        public string $name,
        public array $definition,
        public string $status,
        public ?string $errorCode,
        public int $toolCount,
    ) {}

    public static function fromModel(AptRepository $repository): self
    {
        $definition = $repository->definition;
        unset($definition['name']);

        return new self(
            id: $repository->id,
            nodeId: $repository->node_id,
            name: $repository->name,
            definition: $definition,
            status: $repository->status->value,
            errorCode: $repository->error_code,
            toolCount: is_int($repository->getAttribute('tools_count')) ? $repository->getAttribute('tools_count') : $repository->tools()->count(),
        );
    }
}
