<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InstanceSmokeResultData extends Data
{
    /** @param list<array{name: string, url: string}> $artifacts */
    public function __construct(
        public int $deploymentId, public string $commit, public string $release, public string $baseUrl,
        public string $workflow, public string $ref,
        public string $state = 'pending', public ?int $runId = null, public ?int $runAttempt = null,
        public ?string $workflowSha = null,
        public ?string $runUrl = null, public ?string $conclusion = null, public int $tests = 0, public int $failures = 0,
        public int $errors = 0, public int $skipped = 0, public string $output = '', public array $artifacts = [],
    ) {}
}
