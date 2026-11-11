<?php

declare(strict_types=1);

namespace App\Actions\Hibernation;

use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\InstanceCheckoutInspector;
use App\Domain\Hibernation\InstanceRuntimeReadiness;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\NativeViteCaddyPublication;
use App\Models\Instance;
use App\Models\Process;
use SensitiveParameter;

final readonly class ActivateInstanceRuntimeAction
{
    public function __construct(
        private DevelopmentHibernationPolicy $policy,
        private ProcessAdmissionLock $admissions,
        private ProcessRuntimeManager $runtime,
        private HibernationMarkerStore $markers,
        private InstanceRuntimeReadiness $readiness,
        private InstanceCheckoutInspector $checkouts,
        private NativeViteCaddyPublication $publication,
    ) {}

    public function execute(#[SensitiveParameter] Instance $instance, bool $retry = false): void
    {
        $instance->loadMissing('node');

        // An Instance that moves into a container stays behind the wake page until the move has ended, so nothing writes its
        // checkout or its database meanwhile. The move writes the awake marker itself.
        if (InstanceMigration::running($instance)) {
            return;
        }

        if (! $this->policy->appliesToInstance($instance)) {
            throw new HibernationException(
                errorCode: 'hibernation.target_ineligible',
                message: "Instance [{$instance->name}] is not an app-dev development target.",
                status: 404,
            );
        }

        $instanceId = $instance->id;

        try {
            $this->admissions->run([$instanceId], function () use ($instance, $instanceId, $retry): void {
                if ($retry) {
                    $this->publication->retry($instance);
                }
                $instance->load('processes');
                $key = RuntimeHibernation::key($instanceId);

                if ($this->markers->isCold($instance->node, $key)) {
                    $this->checkouts->restore($instance, $this->checkouts->inspect($instance));
                }

                $running = $this->desiredRunning($instance);

                foreach ($running as $process) {
                    $this->runtime->start($process, explicit: false);
                }

                $this->readiness->waitUntilReady($instance, $running);

                if ($this->markers->isCold($instance->node, $key)) {
                    $this->markers->clearCold($instance->node, $key);
                }

                $this->markers->markAwake($instance->node, $key);
                $this->publication->forget($instance);
            });
        } catch (ProcessOperationException|ResourceOperationException $exception) {
            throw new HibernationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
            );
        }
    }

    /** @return list<Process> */
    private function desiredRunning(Instance $instance): array
    {
        return array_values($instance->processes
            ->filter(static fn (Process $process): bool => $process->desired_state === DesiredProcessState::Running)
            ->sortBy('id')
            ->all());
    }
}
