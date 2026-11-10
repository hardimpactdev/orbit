<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Carbon\CarbonImmutable;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ConnEnvironmentData extends Data
{
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
        public ?string $powerState,
    ) {}

    public static function fromModel(ConnEnvironment $environment): self
    {
        $environment->loadMissing(['node', 'instance.runtime']);

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
            powerState: $environment->instance?->runtime?->power_state->value,
        );
    }
}
