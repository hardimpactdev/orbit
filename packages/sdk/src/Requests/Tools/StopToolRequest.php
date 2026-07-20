<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Tools;

final class StopToolRequest extends ToolLifecycleRequest
{
    public function __construct(string $tool, ?string $instance = null, ?string $node = null)
    {
        parent::__construct($tool, 'stop', $instance, $node);
    }
}
