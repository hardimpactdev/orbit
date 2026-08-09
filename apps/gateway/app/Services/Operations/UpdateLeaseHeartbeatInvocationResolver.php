<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Operations\UpdateLeaseHeartbeatIdentity;
use App\Data\Operations\UpdateLeaseHeartbeatInvocation;
use Illuminate\Console\Command;
use RuntimeException;

final class UpdateLeaseHeartbeatInvocationResolver
{
    public function resolve(Command $command): UpdateLeaseHeartbeatInvocation
    {
        return new UpdateLeaseHeartbeatInvocation(
            identity: new UpdateLeaseHeartbeatIdentity(
                operationRunId: $this->requiredStringOption($command, 'operation-run-id'),
                fleetLeaseId: $this->positiveIntegerOption($command, 'fleet-lease-id'),
                ownerToken: $this->ownerToken(),
            ),
            parentPid: $this->positiveIntegerOption($command, 'parent-pid'),
            ttlSeconds: $this->positiveIntegerOption($command, 'ttl-seconds'),
            intervalSeconds: $this->positiveIntegerOption($command, 'interval-seconds'),
            once: (bool) $command->option('once'),
        );
    }

    private function requiredStringOption(Command $command, string $name): string
    {
        $value = $command->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("The --{$name} option is required.");
        }

        return trim($value);
    }

    /** @return positive-int */
    private function positiveIntegerOption(Command $command, string $name): int
    {
        $value = $command->option($name);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $integerValue = (int) $value;

            if ($integerValue > 0) {
                return $integerValue;
            }
        }

        throw new RuntimeException("The --{$name} option must be a positive integer.");
    }

    private function ownerToken(): string
    {
        $ownerToken = getenv(UpdateLeaseHeartbeatProcess::OWNER_TOKEN_ENVIRONMENT);

        if (! is_string($ownerToken) || trim($ownerToken) === '') {
            throw new RuntimeException('The update lease heartbeat owner token is required.');
        }

        return trim($ownerToken);
    }
}
