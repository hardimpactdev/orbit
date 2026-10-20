<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeInstanceStateInspector::class)]
interface InstanceStateInspector
{
    public function inspect(Instance $instance): InstanceInspectionData;
}
