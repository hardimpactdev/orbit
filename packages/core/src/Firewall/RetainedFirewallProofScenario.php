<?php

declare(strict_types=1);

namespace Orbit\Core\Firewall;

final class RetainedFirewallProofScenario
{
    public const string RULE_NAME = 'private-api';

    public const string NODE = 'app-dev-1';

    public const string PORT = '8080';

    public const string PROTOCOL = 'tcp';

    public const string SOURCE = '192.168.1.0/24';

    public const string PROTECTED_COMMENT = 'protected unrelated rule';

    public const string MANAGED_IDENTITY = 'orbit:private-api';

    /**
     * Seed an unrelated same-port allow, an old managed identity, and a broad deny.
     *
     * @return list<string>
     */
    public static function seedUfwCommands(): array
    {
        $port = self::PORT;
        $protocol = self::PROTOCOL;
        $protected = self::PROTECTED_COMMENT;
        $managed = self::MANAGED_IDENTITY;

        return [
            "sudo ufw allow in on wg-orbit comment 'Orbit node security baseline permits SSH only through WireGuard.'",
            'sudo ufw --force enable',
            "sudo ufw allow from 10.6.0.0/24 to any port {$port} proto {$protocol} comment '{$protected}'",
            "sudo ufw allow from any to any port {$port} proto {$protocol} comment '{$managed}'",
            "sudo ufw deny {$port}/{$protocol}",
        ];
    }

    /**
     * @return list<string>
     */
    public static function operatorSteps(): array
    {
        return ['allow', 'list', 'doctor', 'remove'];
    }
}
