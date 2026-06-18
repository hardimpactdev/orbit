<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

use Throwable;

/**
 * Outcome of a {@see StepTree::run()} pass: whether every step completed, the
 * throwable that aborted it (if any), and each step's returned value.
 */
final readonly class StepTreeResult
{
    /**
     * @param  list<mixed>  $results
     */
    private function __construct(
        public bool $completed,
        public ?Throwable $error,
        public array $results,
    ) {}

    /**
     * @param  list<mixed>  $results
     */
    public static function completed(array $results): self
    {
        return new self(true, null, $results);
    }

    /**
     * @param  list<mixed>  $results
     */
    public static function failed(Throwable $error, array $results): self
    {
        return new self(false, $error, $results);
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }
}
