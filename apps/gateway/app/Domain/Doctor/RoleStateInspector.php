<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Infrastructure\Doctor\NativeRoleStateInspector;
use App\Models\NodeRole;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeRoleStateInspector::class)]
interface RoleStateInspector
{
    /** @throws DoctorInspectionException */
    public function inspect(NodeRole $role): RoleInspectionData;
}
