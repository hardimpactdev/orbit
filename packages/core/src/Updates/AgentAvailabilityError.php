<?php

declare(strict_types=1);

namespace Orbit\Core\Updates;

/** @mago-expect lint:cyclomatic-complexity */
final class AgentAvailabilityError
{
    public const string Unavailable = 'orbit_agent_unavailable';

    public const string DesktopNotRunning = 'orbit_desktop_not_running';

    public const string GatewayUnreachable = 'node.agent_unreachable';

    public static function publicCode(string $code): string
    {
        return $code === self::GatewayUnreachable ? self::Unavailable : $code;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function publicMeta(array $meta): array
    {
        $node = is_string($meta['node'] ?? null) ? $meta['node'] : null;
        $platform = is_string($meta['platform'] ?? null) ? $meta['platform'] : null;
        $enriched = $meta;

        if ($node !== null) {
            $enriched['node'] = $node;
        }

        if ($platform !== null) {
            $enriched['platform'] = $platform;
        }

        if (self::isMacosPlatform($platform) && ! array_key_exists('remediation', $enriched)) {
            $enriched['remediation'] = 'Open Orbit Desktop to start Orbit Agent.';
        }

        return $enriched;
    }

    public static function publicMessage(string $message, array $meta): string
    {
        $node = is_string($meta['node'] ?? null) ? $meta['node'] : null;

        if ($node === null) {
            return $message;
        }

        if (self::isMacosPlatform(is_string($meta['platform'] ?? null) ? $meta['platform'] : null)) {
            return "Orbit Agent is unavailable on node {$node}. Open Orbit Desktop to start it.";
        }

        return "Orbit Agent is unavailable on node {$node}.";
    }

    private static function isMacosPlatform(?string $platform): bool
    {
        if ($platform === null) {
            return false;
        }

        $platform = strtolower($platform);

        return str_contains($platform, 'darwin') || str_contains($platform, 'macos');
    }
}
