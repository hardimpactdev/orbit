<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Models\Process;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeProcessStateInspector::class)]
interface ProcessStateInspector
{
    /** @throws DoctorInspectionException */
    public function inspect(Process $process): ProcessInspectionData;
}
