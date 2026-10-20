<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Infrastructure\Tools\NativeToolInspector;
use App\Models\Tool;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeToolInspector::class)]
interface ToolInspector
{
    public function inspect(Tool $tool): ToolInspectionData;

    /**
     * @param  list<Tool>  $tools
     * @return list<ToolInspectionOutcome>
     */
    public function inspectMany(array $tools): array;
}
