<?php

declare(strict_types=1);

namespace App\Services\Operations;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-ignore lint:excessive-parameter-list
 */
final readonly class LocalFleetUpdateInstallAgentServicePayload
{
    public function __construct(
        public string $unitName,
        public string $execStart,
        public string $configPath,
        public string $config,
        public string $caPath,
        public string $caPem,
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
            config: self::config($payload['config'] ?? null),
            caPath: LocalFleetUpdateInstallCliPayloadField::absolutePath(
                $payload['ca_path'] ?? null,
                'agent_service.ca_path',
            ),
            caPem: self::caPem($payload['ca_pem'] ?? null),
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

    private static function config(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '' && ! str_contains($value, "\0")) {
            return $value;
        }

        throw LocalFleetUpdateInstallCliPayloadField::validationFailure('agent_service.config');
    }

    private static function caPem(mixed $value): string
    {
        if (
            is_string($value)
            && str_contains($value, '-----BEGIN CERTIFICATE-----')
            && str_contains($value, '-----END CERTIFICATE-----')
            && ! str_contains($value, "\0")
        ) {
            return $value;
        }

        throw LocalFleetUpdateInstallCliPayloadField::validationFailure('agent_service.ca_pem');
    }

    private static function user(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*[$]?\z/', $value) === 1) {
            return $value;
        }

        throw LocalFleetUpdateInstallCliPayloadField::validationFailure('agent_service.user');
    }
}
