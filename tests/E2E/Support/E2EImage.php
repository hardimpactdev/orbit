<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

enum E2EImage: string
{
    case Blank = 'blank';
    case Control = 'control';
    case Gateway = 'gateway';
}
