<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use RuntimeException;

/** Work that cannot be interrupted must finish before cancellation frees the target. */
final class OperationStopPending extends RuntimeException {}
