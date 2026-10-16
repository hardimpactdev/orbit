<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use Closure;

final readonly class DeploymentRequest
{
    /**
     * @param  (Closure(DeploymentEvent): void)|null  $output
     * @param  (Closure(DeploymentProgressPhase, ?string): void)|null  $phase
     */
    public function __construct(
        private ?Closure $output = null,
        private ?Closure $phase = null,
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
