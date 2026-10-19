<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/** A workspace operation waits; the scheduler retries it without failure accounting. */
final class TaskWorkspaceWaiting extends RuntimeException {}
