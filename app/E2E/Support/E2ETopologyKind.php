<?php

declare(strict_types=1);

namespace App\E2E\Support;

enum E2ETopologyKind: string
{
    case Operator = 'operator';
    case OperatorGateway = 'operator_gateway';
    case OperatorGatewayAppdev = 'operator_gateway_app-dev';
    case OperatorGatewayAppdevAppprod = 'operator_gateway_app-dev_app-prod';
    case OperatorGatewayAppdevAppprodAgent = 'operator_gateway_app-dev_app-prod_agent';
    case OperatorGatewayAppprodIngress = 'operator_gateway_app-prod_ingress';

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
            'operator-gateway' => self::OperatorGateway,
            'operator-gateway-app-dev' => self::OperatorGatewayAppdev,
            'operator-gateway-app-dev-app-prod' => self::OperatorGatewayAppdevAppprod,
            'operator-gateway-app-dev-app-prod-agent' => self::OperatorGatewayAppdevAppprodAgent,
            'operator-gateway-app-prod-ingress' => self::OperatorGatewayAppprodIngress,
            'operator-gateway-appdev' => self::OperatorGatewayAppdev,
            'operator-gateway-appdev-appprod' => self::OperatorGatewayAppdevAppprod,
            'operator-gateway-appdev-appprod-agent' => self::OperatorGatewayAppdevAppprodAgent,
            'operator-gateway-appprod-ingress' => self::OperatorGatewayAppprodIngress,
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
            self::OperatorGateway => ['operator-gateway', 'control-gateway'],
            self::OperatorGatewayAppdev => ['operator-gateway-app-dev', 'operator-gateway-appdev', 'control-gateway-dev'],
            self::OperatorGatewayAppdevAppprod => ['operator-gateway-app-dev-app-prod', 'operator-gateway-appdev-appprod', 'control-gateway-dev-prod'],
            self::OperatorGatewayAppdevAppprodAgent => ['operator-gateway-app-dev-app-prod-agent', 'operator-gateway-appdev-appprod-agent'],
            self::OperatorGatewayAppprodIngress => ['operator-gateway-app-prod-ingress', 'operator-gateway-appprod-ingress'],
        };
    }

    public function dockerImageSlug(): string
    {
        return match ($this) {
            self::Operator => 'operator',
            self::OperatorGateway => 'operator_gateway',
            self::OperatorGatewayAppdev => 'operator_gateway_app-dev',
            self::OperatorGatewayAppdevAppprod => 'operator_gateway_app-dev_app-prod',
            self::OperatorGatewayAppdevAppprodAgent => 'operator_gateway_app-dev_app-prod_agent',
            self::OperatorGatewayAppprodIngress => 'operator_gateway_app-prod_ingress',
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
