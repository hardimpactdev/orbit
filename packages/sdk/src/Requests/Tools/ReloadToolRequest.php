<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Tools;

final class ReloadToolRequest extends ToolLifecycleRequest
{
    public function __construct(string $tool, ?string $app = null, ?string $node = null)
    {
        parent::__construct($tool, 'reload', $app, $node);
    }
}
