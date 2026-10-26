<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use Closure;

final readonly class DeploymentRequest
{
    /**
     * @param  (Closure(DeploymentEvent): void)|null  $output
     * @param  (Closure(DeploymentProgressPhase, ?string): void)|null  $phase
     * @param  int|null  $operation  The operation that runs the deployment; a Node publishes live step output on its channel.
     */
    public function __construct(
        private ?Closure $output = null,
        private ?Closure $phase = null,
        public ?int $operation = null,
    ) {}

    public static function withoutOutput(): self
    {
        return new self;
    }

    public function emit(DeploymentEvent $event): void
    {
        if ($this->output !== null) {
            ($this->output)($event);
        }
    }

    public function emitPhase(DeploymentProgressPhase $phase, ?string $stepName = null): void
    {
        if ($this->phase !== null) {
            ($this->phase)($phase, $stepName);
        }
    }
}
