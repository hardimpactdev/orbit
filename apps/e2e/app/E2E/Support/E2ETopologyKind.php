<?php

declare(strict_types=1);

namespace App\E2E\Support;

enum E2ETopologyKind: string
{
    case Operator = 'operator';
    case OperatorGateway = 'operator_gateway';
    case OperatorGatewayAppdev = 'operator_gateway_app-dev';
    case OperatorGatewayAppdevAppprod = 'operator_gateway_app-dev_app-prod';
    case OperatorGatewayAgent = 'operator_gateway_agent';
    case OperatorGatewayAppdevAppprodAgent = 'operator_gateway_app-dev_app-prod_agent';
    case OperatorGatewayAppprodIngress = 'operator_gateway_app-prod_ingress';
    case OperatorGatewayAppdevWebsocket = 'operator_gateway_app-dev_websocket';
    case OperatorGatewayAppdevAppprodWebsocket = 'operator_gateway_app-dev_app-prod_websocket';
    case OperatorGatewayAppdevAppprodAgentWebsocket = 'operator_gateway_app-dev_app-prod_agent_websocket';

    public static function tryFromInput(string $value): ?self
    {
        return match ($value) {
            'operator-gateway' => self::OperatorGateway,
            'operator-gateway-dev' => self::OperatorGatewayAppdev,
            'operator-gateway-dev-prod' => self::OperatorGatewayAppdevAppprod,
            'operator-gateway-app-dev' => self::OperatorGatewayAppdev,
            'operator-gateway-app-dev-app-prod' => self::OperatorGatewayAppdevAppprod,
            'operator-gateway-agent' => self::OperatorGatewayAgent,
            'operator-gateway-app-dev-app-prod-agent' => self::OperatorGatewayAppdevAppprodAgent,
            'operator-gateway-app-prod-ingress' => self::OperatorGatewayAppprodIngress,
            'operator-gateway-app-dev-websocket' => self::OperatorGatewayAppdevWebsocket,
            'operator-gateway-app-dev-app-prod-websocket' => self::OperatorGatewayAppdevAppprodWebsocket,
            'operator-gateway-app-dev-app-prod-agent-websocket' => self::OperatorGatewayAppdevAppprodAgentWebsocket,
            'operator-gateway-appdev' => self::OperatorGatewayAppdev,
            'operator-gateway-appdev-appprod' => self::OperatorGatewayAppdevAppprod,
            'operator-gateway-appdev-appprod-agent' => self::OperatorGatewayAppdevAppprodAgent,
            'operator-gateway-appprod-ingress' => self::OperatorGatewayAppprodIngress,
            'operator-gateway-appdev-websocket' => self::OperatorGatewayAppdevWebsocket,
            'operator-gateway-appdev-appprod-websocket' => self::OperatorGatewayAppdevAppprodWebsocket,
            'operator-gateway-appdev-appprod-agent-websocket' => self::OperatorGatewayAppdevAppprodAgentWebsocket,
            'operator-gateway-dev-websocket' => self::OperatorGatewayAppdevWebsocket,
            'operator-gateway-dev-prod-websocket' => self::OperatorGatewayAppdevAppprodWebsocket,
            default => self::tryFrom($value),
        };
    }

    /**
     * @return list<string>
     */
    public function deprecatedValues(): array
    {
        return match ($this) {
            self::Operator => [],
            self::OperatorGateway => ['operator-gateway'],
            self::OperatorGatewayAppdev => ['operator-gateway-app-dev', 'operator-gateway-appdev', 'operator-gateway-dev'],
            self::OperatorGatewayAppdevAppprod => ['operator-gateway-app-dev-app-prod', 'operator-gateway-appdev-appprod', 'operator-gateway-dev-prod'],
            self::OperatorGatewayAgent => ['operator-gateway-agent'],
            self::OperatorGatewayAppdevAppprodAgent => ['operator-gateway-app-dev-app-prod-agent', 'operator-gateway-appdev-appprod-agent'],
            self::OperatorGatewayAppprodIngress => ['operator-gateway-app-prod-ingress', 'operator-gateway-appprod-ingress'],
            self::OperatorGatewayAppdevWebsocket => ['operator-gateway-app-dev-websocket', 'operator-gateway-appdev-websocket', 'operator-gateway-dev-websocket'],
            self::OperatorGatewayAppdevAppprodWebsocket => ['operator-gateway-app-dev-app-prod-websocket', 'operator-gateway-appdev-appprod-websocket', 'operator-gateway-dev-prod-websocket'],
            self::OperatorGatewayAppdevAppprodAgentWebsocket => ['operator-gateway-app-dev-app-prod-agent-websocket', 'operator-gateway-appdev-appprod-agent-websocket'],
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
