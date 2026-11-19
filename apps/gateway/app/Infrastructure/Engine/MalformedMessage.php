<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use RuntimeException;

/** A bus message that cannot be read: it would fail the same way on every delivery, so it is terminated, not retried. */
final class MalformedMessage extends RuntimeException {}
