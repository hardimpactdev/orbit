<?php

declare(strict_types=1);

namespace App\E2E\Support;

enum E2ETopologyKind: string
{
    case Operator = 'operator';
    case OperatorGateway = 'operator-gateway';
    case OperatorGatewayAppdev = 'operator-gateway-appdev';
    case OperatorGatewayAppdevAppprod = 'operator-gateway-appdev-appprod';
    case OperatorGatewayAppdevAppprodAgent = 'operator-gateway-appdev-appprod-agent';

    #[\Deprecated(message: 'Migration alias for one E2E terminology window.')]
    public const self Control = self::Operator;

    #[\Deprecated(message: 'Migration alias for one E2E terminology window.')]
    public const self ControlGateway = self::OperatorGateway;

    #[\Deprecated(message: 'Migration alias for one E2E terminology window.')]
    public const self ControlGatewayDev = self::OperatorGatewayAppdev;

    #[\Deprecated(message: 'Migration alias for one E2E terminology window.')]
    public const self ControlGatewayDevProd = self::OperatorGatewayAppdevAppprod;

    public static function tryFromInput(string $value): ?self
    {
        return match ($value) {
            'control' => self::Operator,
            'control-gateway' => self::OperatorGateway,
            'control-gateway-dev' => self::OperatorGatewayAppdev,
            'control-gateway-dev-prod' => self::OperatorGatewayAppdevAppprod,
            default => self::tryFrom($value),
        };
    }

    /**
     * @return list<string>
     */
    public function deprecatedValues(): array
    {
        return match ($this) {
            self::Operator => ['control'],
            self::OperatorGateway => ['control-gateway'],
            self::OperatorGatewayAppdev => ['control-gateway-dev'],
            self::OperatorGatewayAppdevAppprod => ['control-gateway-dev-prod'],
            self::OperatorGatewayAppdevAppprodAgent => [],
        };
    }

    public function featureGroup(): string
    {
        return 'e2e-feature-'.$this->value;
    }

    /**
     * @return list<string>
     */
    public function deprecatedFeatureGroups(): array
    {
        return array_map(
            static fn (string $value): string => 'e2e-feature-'.$value,
            $this->deprecatedValues(),
        );
    }
}
