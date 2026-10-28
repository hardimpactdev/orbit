<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceSmokeContextData extends Data
{
    /**
     * @param  list<string>  $baseUrls
     * @param  list<string>  $retainedReleases
     */
    public function __construct(public int $instanceId, public int $projectId, public string $repositoryUrl,
        public bool $production, public array $baseUrls, public ?InstanceSmokeSelectionData $selected,
        public array $retainedReleases) {}
}
