<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Carbon\CarbonImmutable;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapOutputName(SnakeCaseMapper::class)]
final class ConnEnvironmentData extends Data
{
    /** @param array{state:string,reason:string}|Optional $git */
    public function __construct(
        public string $environmentId,
        public string $label,
        public string $url,
        public ?string $serverVersion,
        public ?string $registeredBy,
        public string $registeredAt,
        public string $adminSessionExpiresAt,
        public string $status,
        public bool $cleanupPending,
        public int $cleanupVersion,
        public ?int $instanceId,
        public ?int $projectId,
        public ?string $projectName,
        public ?string $projectSlug,
        public ?string $instanceName,
        public ?string $nodeName,
        public ?string $instanceUrl,
        public ?string $powerState,
        public array|Optional $git = new Optional,
    ) {}

    public static function fromModel(ConnEnvironment $environment, bool $includeGit = false): self
    {
        $environment->loadMissing(['node', 'instance.runtime', 'instance.project', 'instance.node', 'instance.routes']);

        return new self(
            environmentId: $environment->environment_id,
            label: $environment->label,
            url: $environment->url,
            serverVersion: $environment->server_version,
            registeredBy: $environment->node?->name,
            registeredAt: $environment->registered_at->toIso8601String(),
            adminSessionExpiresAt: $environment->admin_session_expires_at->toIso8601String(),
            status: $environment->admin_session_expires_at->isAfter(CarbonImmutable::now()) ? 'registered' : 'session_expired',
            cleanupPending: $environment->cleanup_version > $environment->cleanup_completed_version,
            cleanupVersion: $environment->cleanup_version,
            // The server of a container Instance names it, and the container's power state: a stopped one is asleep.
            instanceId: $environment->instance_id,
            projectId: $environment->instance?->project_id,
            projectName: $environment->instance?->project->name,
            projectSlug: $environment->instance?->project->slug,
            instanceName: $environment->instance?->name,
            nodeName: $environment->instance?->node->name,
            instanceUrl: $environment->instance?->url(),
            powerState: $environment->instance?->runtime?->power_state->value,
            // Only orbit-agent may collect Git facts; no container observations exist yet.
            git: $includeGit && $environment->instance_id !== null
                ? ['state' => 'unknown', 'reason' => 'not_observed'] : new Optional,
        );
    }
}
