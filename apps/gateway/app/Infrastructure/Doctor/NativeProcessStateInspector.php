<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ProcessInspectionData;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Doctor\SystemdProcessObservationData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Process;
use Throwable;

final readonly class NativeProcessStateInspector implements ProcessStateInspector
{
    public function __construct(
        private ProcessTargetResolver $targets,
        private AgentCommandRunner $agents,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
        private CommandDeadline $deadline,
        private AgentContract $contract,
    ) {}

    public function inspect(Process $process): ProcessInspectionData
    {
        try {
            $node = $this->targets->forInspection($process)->node;
            $result = $this->agents->run($node, 'doctor.inspect-process', [
                'process_id' => $process->id,
                'runtime' => $process->runtime->value,
                'name' => $process->runtime === ProcessRuntime::Systemd ? $this->systemd->unitName($process) : $this->docker->containerName($process),
                'desired' => $process->desired_state->value,
            ], $this->deadline->cap($this->contract->command('doctor.inspect-process')->timeoutSeconds));

            $present = $result['present'] ?? null;
            $samples = $result['systemd_observations'] ?? null;
            if (! is_bool($present) || ! is_array($samples) || ! array_is_list($samples)) {
                throw new DoctorInspectionException;
            }
            $observations = [];
            foreach ($samples as $sample) {
                if (! is_array($sample) || ! is_string($sample['active_state'] ?? null) || ! is_string($sample['sub_state'] ?? null)
                    || ! array_key_exists('restarts', $sample) || ($sample['restarts'] !== null && ! is_int($sample['restarts']))) {
                    throw new DoctorInspectionException;
                }
                $observations[] = new SystemdProcessObservationData($sample['active_state'], $sample['sub_state'], $sample['restarts']);
            }
            if (($present && $process->runtime === ProcessRuntime::Systemd && $observations === [])
                || ($process->runtime === ProcessRuntime::Docker && $observations !== []) || ! array_key_exists('status', $result)) {
                throw new DoctorInspectionException;
            }
            if ($result['status'] !== null && ! is_string($result['status'])) {
                throw new DoctorInspectionException;
            }
            $status = $result['status'] === null ? null : ProcessInspectionStatus::from($result['status']);

            return new ProcessInspectionData($present, $status, $observations);
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }
}
