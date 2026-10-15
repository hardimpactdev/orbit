<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use InvalidArgumentException;

/**
 * One named step of a deployment: a phase, or a phase and a deploy step's name, such as `before_activation.migrate`.
 * A step that runs a person's command is not resumable, because Orbit cannot know whether it ran.
 */
final readonly class DeploymentStepName
{
    public function __construct(
        public DeploymentProgressPhase $phase,
        public ?string $stepName = null,
    ) {}

    public static function fromName(string $name): self
    {
        [$phase, $stepName] = array_pad(explode('.', $name, 2), 2, null);
        $progress = DeploymentProgressPhase::tryFrom((string) $phase);

        return $progress instanceof DeploymentProgressPhase ? new self($progress, $stepName) : throw new InvalidArgumentException("[{$name}] is not a deployment step.");
    }

    public function name(): string
    {
        return $this->stepName === null ? $this->phase->value : "{$this->phase->value}.{$this->stepName}";
    }

    public function resumable(): bool
    {
        return $this->stepName === null;
    }

    /** The boundary a failure of this step reports on the deployment row. */
    public function boundary(): DeploymentFailureBoundary
    {
        return match ($this->phase) {
            DeploymentProgressPhase::SourcePreparation => DeploymentFailureBoundary::Preparation,
            DeploymentProgressPhase::EnvironmentSync => DeploymentFailureBoundary::Environment,
            DeploymentProgressPhase::BeforeActivation => DeploymentFailureBoundary::BeforeActivation,
            DeploymentProgressPhase::Activation => DeploymentFailureBoundary::Activation,
            DeploymentProgressPhase::PhpRefresh => DeploymentFailureBoundary::CacheRefresh,
            DeploymentProgressPhase::ProcessRestart, DeploymentProgressPhase::AfterActivation, DeploymentProgressPhase::Cleanup => DeploymentFailureBoundary::AfterActivation,
            DeploymentProgressPhase::Rollback => DeploymentFailureBoundary::RollbackSelection,
        };
    }
}
