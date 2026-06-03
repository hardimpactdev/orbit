<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;
use Throwable;

final class E2ETopologyAcquisitionRetainedForDiagnosis extends RuntimeException
{
    /**
     * @param  list<string>  $roles
     * @param  array<string, string>  $instances
     * @param  list<string>  $managedContainers
     * @param  list<string>  $volumes
     * @param  array<string, mixed>|null  $resourceLease
     */
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $host,
        public readonly string $runId,
        public readonly string $network,
        public readonly array $roles,
        public readonly array $instances,
        public readonly array $managedContainers,
        public readonly array $volumes,
        public readonly ?array $resourceLease = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
