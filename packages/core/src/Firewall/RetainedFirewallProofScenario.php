<?php

declare(strict_types=1);

namespace Orbit\Core\Firewall;

final class RetainedFirewallProofScenario
{
    public const string RuleName = 'private-api';

    public const string Node = 'app-dev-1';

    public const string Port = '8080';

    public const string Protocol = 'tcp';

    public const string Source = '192.168.1.0/24';

    public const string ProtectedComment = 'protected unrelated rule';

    public const string ManagedIdentity = 'orbit:private-api';

    /**
     * Seed an unrelated same-port allow, an old managed identity, and a broad deny.
     *
     * @return list<string>
     */
    public static function seedUfwCommands(): array
    {
        $port = self::Port;
        $protocol = self::Protocol;
        $protected = self::ProtectedComment;
        $managed = self::ManagedIdentity;

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
