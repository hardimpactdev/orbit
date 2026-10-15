<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use RuntimeException;

/** A resumed operation planned other steps than its first attempt. */
final class OperationPlanChanged extends RuntimeException {}
