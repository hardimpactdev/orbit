<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;

/** Durable ownership confirmed by the role's machine phase, independent of fleet topology. */
final readonly class GatewayPrivateDnsState
{
    private const string Key = 'gateway.private_dns_owned';

    public function __construct(private SettingRepository $settings) {}

    public function owned(int $nodeId): bool
    {
        return $this->settings->get(new SettingScope(SettingScopeType::Node, $nodeId), self::Key) === 'true';
    }

    /** @param array<string,mixed> $runtime */
    public function record(int $nodeId, array $runtime): void
    {
        $dns = $runtime['gateway_dns'] ?? null;
        if (! is_array($dns)) {
            return;
        }
        if (isset($dns['listener'])) {
            $this->settings->put(new SettingScope(SettingScopeType::Node, $nodeId), self::Key, 'true');
        } elseif (isset($dns['withdraw'])) {
            $this->forget($nodeId);
        }
    }

    public function forget(int $nodeId): void
    {
        $this->settings->delete(new SettingScope(SettingScopeType::Node, $nodeId), self::Key);
    }
}
