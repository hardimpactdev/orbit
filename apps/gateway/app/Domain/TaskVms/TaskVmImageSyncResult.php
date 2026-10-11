<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** What a base image sync did on one host: copied, already current, or failed with a code. */
final readonly class TaskVmImageSyncResult
{
    /** @param  array<string, float>  $stages  each finished stage with its seconds */
    public function __construct(
        public string $nodeName,
        public bool $copied,
        public float $seconds,
        public array $stages,
        public ?string $errorCode = null,
        public ?string $error = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->errorCode === null;
    }

    /** One line for the operator, such as `Node [shark] has the base image after 47.2s (copy 30.1s, …).` */
    public function summary(): string
    {
        $stages = implode(', ', array_map(
            static fn (string $stage, float $seconds): string => sprintf('%s %.1fs', $stage, $seconds),
            array_keys($this->stages),
            $this->stages,
        ));
        $timing = sprintf('after %.1fs', $this->seconds).($stages === '' ? '' : " ({$stages})");

        return match (true) {
            ! $this->succeeded() => "Node [{$this->nodeName}] failed {$timing}: [{$this->errorCode}] {$this->error}",
            $this->copied => "Node [{$this->nodeName}] has the base image {$timing}.",
            default => "Node [{$this->nodeName}] already had the base image.",
        };
    }
}
