<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

enum E2EImage: string
{
    case Blank = 'blank';
    case Base = 'base';
    case Control = 'control';
    case Gateway = 'gateway';
}
