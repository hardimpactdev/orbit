<?php

declare(strict_types=1);

namespace App\Services\OrbitAgentJobs;

use App\Services\Operations\OperationPayloadRejected;
use App\Services\Operations\ResultBoundaryRedactionPolicy;

final readonly class OrbitAgentJobPayloadPolicy
{
    private const array FORBIDDEN_AGENT_KEYS = [
        'command',
        'argv',
        'shell',
        'operationtoken',
        'apikey',
        'bearer',
        'password',
        'secret',
    ];

    public function __construct(
        private ResultBoundaryRedactionPolicy $redaction,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertSafe(array $payload, string $context): void
    {
        foreach ($this->unsafeAgentKeyPaths($payload) as $path) {
            throw new OperationPayloadRejected(
                "operation.{$context}_unsafe: rejected payload at '{$path}' (forbidden_agent_key).",
                errorCode: "operation.{$context}_unsafe",
                meta: [
                    'path' => $path,
                    'reason' => 'forbidden_agent_key',
                ],
            );
        }

        $this->redaction->assertSafe($payload, $context);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function unsafeAgentKeyPaths(array $payload, string $prefix = ''): array
    {
        $paths = [];

        foreach (array_keys($payload) as $key) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_string($key) && $this->isForbiddenAgentKey($key)) {
                $paths[] = $path;
            }

            if (is_array($payload[$key])) {
                $paths = array_merge($paths, $this->unsafeAgentKeyPaths($payload[$key], $path));
            }
        }

        return $paths;
    }

    private function isForbiddenAgentKey(string $key): bool
    {
        $normalized = str_replace(search: ['_', '-'], replace: '', subject: strtolower($key));

        return array_any(
            self::FORBIDDEN_AGENT_KEYS,
            static fn (string $fragment): bool => str_contains($normalized, $fragment),
        );
    }
}
