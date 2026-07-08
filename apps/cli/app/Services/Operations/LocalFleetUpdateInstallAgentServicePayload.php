<?php

declare(strict_types=1);

namespace App\Services\Operations;

final readonly class LocalFleetUpdateInstallAgentServicePayload
{
    public function __construct(
        public string $unitName,
        public string $execStart,
        public string $configPath,
        public string $httpBind,
        public string $user,
    ) {}

    public static function fromPayload(mixed $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('agent_service');
        }

        return new self(
            unitName: self::unitName($payload['unit_name'] ?? null),
            execStart: LocalFleetUpdateInstallCliPayloadField::absolutePath(
                $payload['exec_start'] ?? null,
                'agent_service.exec_start',
            ),
            configPath: LocalFleetUpdateInstallCliPayloadField::absolutePath(
                $payload['config_path'] ?? null,
                'agent_service.config_path',
            ),
            httpBind: self::httpBind($payload['http_bind'] ?? null),
            user: self::user($payload['user'] ?? null),
        );
    }

    private static function unitName(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[A-Za-z0-9_.@-]+(?:\.service)?\z/', $value) === 1) {
            return $value;
        }

        throw LocalFleetUpdateInstallCliPayloadField::validationFailure('agent_service.unit_name');
    }

    private static function httpBind(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[0-9A-Za-z.:-]+:[0-9]{1,5}\z/', $value) === 1) {
            return $value;
        }

        throw LocalFleetUpdateInstallCliPayloadField::validationFailure('agent_service.http_bind');
    }

    private static function user(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*[$]?\z/', $value) === 1) {
            return $value;
        }

        throw LocalFleetUpdateInstallCliPayloadField::validationFailure('agent_service.user');
    }
}
