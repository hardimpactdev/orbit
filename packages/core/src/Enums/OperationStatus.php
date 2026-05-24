<?php

declare(strict_types=1);

namespace Hardimpactdev\OrbitCore\Enums;

enum OperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Rejected = 'rejected';
}
