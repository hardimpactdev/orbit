<?php

declare(strict_types=1);

namespace App\Tools;

final class VitePlusTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux'];

    public function slug(): string
    {
        return 'viteplus';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }
}
