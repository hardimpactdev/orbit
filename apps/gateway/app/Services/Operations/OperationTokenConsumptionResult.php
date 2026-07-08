<?php

declare(strict_types=1);

namespace App\Services\Operations;

enum OperationTokenConsumptionResult: string
{
    case Consumed = 'consumed';
    case AlreadyDispatched = 'operation.already_dispatched';
    case MissingOperation = 'operation.not_found';
}
