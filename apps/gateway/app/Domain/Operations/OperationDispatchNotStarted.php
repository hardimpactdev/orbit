<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;

/** Internal transport proof that destination dispatch has not begun; never inferred from remote output. */
final class OperationDispatchNotStarted extends ResourceOperationException {}
