<?php

declare(strict_types=1);

namespace App\E2E\Support;

enum E2ETopologyKind: string
{
    case Control = 'control';
    case ControlGateway = 'control-gateway';
    case ControlGatewayDev = 'control-gateway-dev';
    case ControlGatewayDevProd = 'control-gateway-dev-prod';
}
