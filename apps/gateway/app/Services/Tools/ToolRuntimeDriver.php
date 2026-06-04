<?php

declare(strict_types=1);

namespace App\Services\Tools;

interface ToolRuntimeDriver
{
    public function implementationKey(): string;

    public function label(): string;
}
