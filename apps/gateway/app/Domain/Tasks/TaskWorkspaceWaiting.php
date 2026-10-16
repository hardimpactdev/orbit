<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/** Admission is queued; the scheduler retries it without failure accounting. */
final class TaskWorkspaceWaiting extends RuntimeException {}
