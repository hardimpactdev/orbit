<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class AutomaticDeployPublicationData extends Data
{
    public function __construct(
        public int $resumedAfter,
        public int $lastDeploymentId,
        public string $historyVersion,
        public string $result,
        public ?string $commit,
        public ?string $head,
        public string $since,
        public ?string $errorCode,
        public ?string $message,
        public ?InstanceAutoDeployPauseData $pause,
    ) {}

    /** @return array<string, mixed> */
    public function projection(): array
    {
        return array_diff_key($this->toArray(), array_flip(['resumed_after', 'last_deployment_id', 'history_version']));
    }
}
