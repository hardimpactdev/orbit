<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use SensitiveParameter;
use stdClass;

final readonly class ConnLaunchRequestData
{
    public function __construct(
        public string $launchId,
        public int $expectedProfileId,
        public int $expectedNodeId,
        public int $projectId,
        public ?string $workspaceId,
        public string $targetKind,
        public ?string $name,
        public ?int $instanceId,
        public string $source,
        #[SensitiveParameter] private stdClass $body,
    ) {}

    public function privateRequest(): string
    {
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array{launch_id: string, project_id: int, target_kind: string} */
    public function __debugInfo(): array
    {
        return ['launch_id' => $this->launchId, 'project_id' => $this->projectId, 'target_kind' => $this->targetKind];
    }
}
