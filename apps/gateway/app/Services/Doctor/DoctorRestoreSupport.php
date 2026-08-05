<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsPublicProxyDoctorProbe;
use App\Services\Apps\AppsFixer;
use App\Services\DatabaseConnections\DatabaseConnectionRestorer;
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Nodes\NodesProbe;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\S3\S3ProxyDoctorProbe;
use App\Services\Schedules\SchedulesFixer;
use App\Services\Tools\ToolsFixer;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;
use LogicException;

/**
 * Aggregates family/handler-owned Doctor restore support.
 *
 * Support is never a second hand-maintained allowlist. Each source is the same
 * map the corresponding apply/dispatch path consumes (fixer/probe support
 * methods or runner-owned descriptors). Catalog restorable flags and inventory
 * checks call this aggregation only.
 */
final class DoctorRestoreSupport
{
    /**
     * @return array<string, string> code => restore_action id
     */
    public static function map(): array
    {
        /** @var array<string, string>|null $cache */
        static $cache = null;

        if (is_array($cache)) {
            return $cache;
        }

        $map = [];

        foreach (self::sources() as $source) {
            foreach ($source as $code => $action) {
                if ($code === '' || $action === '') {
                    throw new LogicException('Doctor restore support sources must map non-empty codes to action ids.');
                }

                if (array_key_exists($code, $map) && $map[$code] !== $action) {
                    throw new LogicException(
                        "Conflicting Doctor restore actions for '{$code}': '{$map[$code]}' vs '{$action}'.",
                    );
                }

                $map[$code] = $action;
            }
        }

        ksort($map);
        $cache = $map;

        return $map;
    }

    /**
     * Family/handler restore maps actually consumed by dispatch.
     *
     * @return list<array<string, string>>
     */
    public static function sources(): array
    {
        return [
            NodesProbe::restoreSupport(),
            DoctorDnsProjectionRestoreSupport::restoreSupport(),
            DnsRuntimeProbe::restoreSupport(),
            DoctorRestoreActionId::map(SchedulesFixer::GatewayRestorableCodes),
            AppsFixer::restoreSupport(),
            self::instanceAliases(AppsFixer::restoreSupport()),
            ToolsFixer::restoreSupport(),
            ProxyRouteFixer::restoreSupport(),
            WebSocketProxyDoctorProbe::restoreSupport(),
            S3ProxyDoctorProbe::restoreSupport(),
            AnalyticsProxyDoctorProbe::restoreSupport(),
            AnalyticsPublicProxyDoctorProbe::restoreSupport(),
            FirewallRuleFixer::restoreSupport(),
            DoctorProcessRestoreSupport::restoreSupport(),
            DatabaseConnectionRestorer::restoreSupport(),
        ];
    }

    public static function supports(string $code): bool
    {
        return array_key_exists($code, self::map());
    }

    public static function actionId(string $code): ?string
    {
        return self::map()[$code] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::map());
    }

    /**
     * Gateway-scoped schedule keys that SchedulesFixer::fixGateway handles
     * without a schedule row. Alias of the fixer-owned support keys.
     *
     * @return list<string>
     */
    public static function scheduleGatewayCodes(): array
    {
        return SchedulesFixer::GatewayRestorableCodes;
    }

    /**
     * Public instance.* aliases for internal app.* restore support.
     *
     * @param  array<string, string>  $appSupport
     * @return array<string, string>
     */
    private static function instanceAliases(array $appSupport): array
    {
        $aliases = [];

        foreach ($appSupport as $code => $action) {
            if (! str_starts_with($code, 'app.')) {
                continue;
            }

            $aliases['instance.'.substr($code, 4)] = $action;
        }

        return $aliases;
    }
}
