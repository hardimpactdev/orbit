<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Plugins;

use Saloon\Http\PendingRequest;

trait HasCorrelationHeader
{
    public function bootHasCorrelationHeader(PendingRequest $pendingRequest): void
    {
        $uuid = $this->orbitCorrelationId();

        if (is_string($uuid) && $uuid !== '') {
            $pendingRequest->headers()->add('X-Orbit-Request-Id', $uuid);
        }

        $pendingRequest->headers()->add(
            'X-Orbit-Client',
            $this->orbitClientName(),
        );
    }

    protected function orbitClientName(): string
    {
        return 'api';
    }

    protected function orbitCorrelationId(): ?string
    {
        return null;
    }
}
