<?php

declare(strict_types=1);

namespace App\Domain\Tools;

enum ToolManagerName: string
{
    case Apt = 'apt';
    case Vp = 'vp';
    case Composer = 'composer';
    case Brew = 'brew';
    case BrewCask = 'brew-cask';

    public function operationLockKey(int $nodeId): string
    {
        return "node_tools:{$nodeId}:{$this->value}";
    }

    public function scope(): self
    {
        return $this === self::BrewCask ? self::Brew : $this;
    }
}
