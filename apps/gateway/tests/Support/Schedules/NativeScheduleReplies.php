<?php

declare(strict_types=1);

namespace Tests\Support\Schedules;

/** Serve's confirmations for one Schedule's own workflow and trigger frames. */
final class NativeScheduleReplies
{
    /** Serve assigns each new workflow its own id. */
    public static function workflowId(string $name): string
    {
        return \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, $name)->toString();
    }

    /** @param array<string,mixed> $request
     * @return list<array<string,mixed>>|null
     */
    public static function for(array $request): ?array
    {
        $payload = $request['payload'] ?? [];
        $data = match ($request['type']) {
            'vault.put' => ['vaultName' => $payload['vaultName'], 'secretKey' => $payload['key'], 'vaultType' => 'local_encryption'],
            'workflow.create' => ['id' => self::workflowId($payload['name']), 'name' => $payload['name']],
            'workflow.edit', 'workflow.delete', 'workflow.trigger.remove' => ['ok' => true],
            'workflow.trigger.set' => ['workflowName' => $payload['workflowName'], 'entry' => ['schedule' => $payload['schedule']]],
            default => null,
        };

        return $data === null ? null : [['type' => $request['type'], 'id' => $request['id'], 'payload' => ['data' => $data]]];
    }
}
