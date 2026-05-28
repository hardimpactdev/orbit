<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

enum ProgressEventType: string
{
    case Tree = 'tree';
    case Step = 'step';
    case Complete = 'complete';
    case Error = 'error';
}
