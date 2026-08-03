<?php

declare(strict_types=1);

namespace Orbit\Core\Tools;

/**
 * Canonical tool run-script actions accepted by the gateway-to-CLI
 * `internal:tool:run-script` contract. Gateway dispatchers and CLI payload
 * validation must share this list so a gateway-emitted action cannot be
 * rejected again on the target CLI.
 */
enum ToolRunScriptAction: string
{
    case Install = 'install';
    case Update = 'update';
    case Remove = 'remove';
    case Preflight = 'preflight';
    case Probe = 'probe';
    case ProbeImages = 'probe-images';
    case ProbeMany = 'probe-many';
    case ProbePhpCli = 'probe-php-cli';
    case Reconfigure = 'reconfigure';
    case Start = 'start';
    case Stop = 'stop';
    case Restart = 'restart';
    case Logs = 'logs';
    case Credentials = 'credentials';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $action): string => $action->value,
            self::cases(),
        );
    }

    public static function isAllowed(string $action): bool
    {
        return self::tryFrom($action) instanceof self;
    }

    public static function fromString(string $action): self
    {
        $resolved = self::tryFrom($action);

        if (! $resolved instanceof self) {
            throw new \InvalidArgumentException("Tool run payload action '{$action}' is invalid.");
        }

        return $resolved;
    }
}
