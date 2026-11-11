<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use RuntimeException;

/** Roll back family effects before the reader settles the exact native terminal under a fresh row lock. */
final class NativeTerminalNeedsSettlement extends RuntimeException {}
