<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class UpdateLeaseReservationExpired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Fleet update reservation expired before the runner claimed it.');
    }
}
