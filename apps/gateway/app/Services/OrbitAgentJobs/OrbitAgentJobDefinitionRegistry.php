<?php

declare(strict_types=1);

namespace App\Services\OrbitAgentJobs;

use InvalidArgumentException;

final readonly class OrbitAgentJobDefinitionRegistry
{
    public const string TYPE_NOOP = 'noop';

    public const string TYPE_APP_DEV_CONVERGENCE = 'app-dev-convergence';

    private const array APP_DEV_TOOL_CATALOG = [
        'docker',
        'php-cli',
        'composer',
        'laravel-installer',
        'caddy',
    ];

    /**
     * @return array{
     *     operation: string,
     *     role: string,
     *     tld: string,
     *     tools: list<string>,
     * }
     */
    public function appDevConvergencePayload(string $tld): array
    {
        $tld = trim($tld);

        if ($tld === '') {
            throw new InvalidArgumentException('Orbit Agent app-dev convergence jobs require an app-dev TLD.');
        }

        return [
            'operation' => 'app_dev_convergence',
            'role' => 'app-dev',
            'tld' => $tld,
            'tools' => self::APP_DEV_TOOL_CATALOG,
        ];
    }

    public function assertSupportedType(string $type): void
    {
        if (in_array($type, [self::TYPE_NOOP, self::TYPE_APP_DEV_CONVERGENCE], strict: true)) {
            return;
        }

        throw new InvalidArgumentException(
            'Orbit Agent jobs only support typed noop and app-dev convergence work.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertPayloadMatchesType(string $type, array $payload): void
    {
        if ($type !== self::TYPE_APP_DEV_CONVERGENCE) {
            return;
        }

        $tld = $payload['tld'] ?? null;

        if (is_string($tld) && $payload === $this->appDevConvergencePayload($tld)) {
            return;
        }

        throw new InvalidArgumentException(
            'Orbit Agent app-dev convergence jobs require the typed app-dev convergence payload.',
        );
    }

    public function internalCommandFor(string $type): string
    {
        return match ($type) {
            self::TYPE_NOOP => 'orbit-agent:noop',
            self::TYPE_APP_DEV_CONVERGENCE => 'orbit-agent:app-dev-convergence',
            default => throw new InvalidArgumentException('Unsupported Orbit Agent job type.'),
        };
    }
}
