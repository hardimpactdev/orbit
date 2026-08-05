<?php

declare(strict_types=1);

namespace Orbit\Core\Tools;

/**
 * Canonical tool run-script actions for gateway-to-CLI
 * `internal:tool:run-script`. Gateway dispatch and CLI validation share this list.
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
}
