<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Infrastructure\Doctor\NativeGatewayVpnStateInspector;
use App\Models\NodeRole;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeGatewayVpnStateInspector::class)]
interface GatewayVpnStateInspector
{
    /** @throws DoctorInspectionException */
    public function inspect(NodeRole $role): GatewayVpnInspectionData;
}
