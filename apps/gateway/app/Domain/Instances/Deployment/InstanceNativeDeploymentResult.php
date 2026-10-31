<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Models\Operation;

/** Validates bounded native facts and derives history data; performs no execution or receipt lookup. */
final readonly class InstanceNativeDeploymentResult
{
    private function __construct(private DeploymentResult $result, public int $warnings, public string $outputTail = '', public ?string $failedStep = null, public ?string $stepOutcome = null) {}

    /** @param array<string,mixed> $payload */
    public static function from(Operation $operation, string $status, ?string $code, array $payload): self
    {
        $unknown = array_key_exists('step_outcome', $payload);
        $keys = ['instance_id', 'commit', 'release', 'previous', 'selected_release', 'selection_confirmed', 'failed_step', 'warnings', 'output_tail', ...($unknown ? ['step_outcome'] : [])];
        if ($operation->kind !== 'instance.deploy' || $operation->target_type !== 'instance'
            || count($payload) !== count($keys) || array_diff($keys, array_keys($payload)) !== []
            || ($payload['instance_id'] ?? null) !== $operation->target_id
            || ! in_array($status, ['succeeded', 'failed', 'cancelled'], true)
            || ($status === 'failed' ? ! is_string($code) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1 : $code !== null)
            || ! is_bool($payload['selection_confirmed']) || ! is_int($payload['warnings'])
            || $payload['warnings'] < 0 || $payload['warnings'] > 64
            || ($payload['output_tail'] !== null && (! is_string($payload['output_tail']) || strlen($payload['output_tail']) > 2048 || str_contains($payload['output_tail'], "\0")
                || ! mb_check_encoding($payload['output_tail'], 'UTF-8') || ($payload['output_tail'] !== '' && ($status !== 'failed' || ! is_string($payload['failed_step'])))))) {
            throw self::invalid();
        }
        $intent = InstanceDeploymentIntent::admitted($operation);
        $commit = $payload['commit'];
        if (($commit !== null && (! is_string($commit) || ! GitCommitSha::isValid($commit)))
            || ($commit !== null && InstanceDeploymentIntent::commit($operation) !== null && $commit !== InstanceDeploymentIntent::commit($operation))) {
            throw self::invalid();
        }
        if ($intent['variant'] !== 'development' || $payload['release'] !== null || $payload['previous'] !== null
            || $payload['selected_release'] !== null || $payload['selection_confirmed'] !== true) {
            throw self::invalid();
        }
        $step = $payload['failed_step'];
        $phases = ['source_preparation', 'vp_environment', 'before_activation', 'verification', 'operation'];
        $steps = array_map(static fn (array $row): string => $row['phase'].'.'.$row['name'], $intent['steps']);
        if ($step !== null && (! is_string($step) || ! in_array($step, [...$phases, ...$steps], true))) {
            throw self::invalid();
        }
        if ($unknown && ($payload['step_outcome'] !== 'unknown' || $status !== 'failed' || $code !== 'deployment.interrupted'
            || $operation->cancel_requested_at === null || ! in_array($step, $steps, true))) {
            throw self::invalid();
        }
        if ($status === 'succeeded') {
            if ($commit === null || $step !== null) {
                throw self::invalid();
            }

            return new self(DeploymentResult::checkedOut($commit), $payload['warnings']);
        }
        $phase = is_string($step) ? explode('.', $step)[0] : 'operation';
        $boundary = match ($phase) {
            'source_preparation', 'vp_environment' => DeploymentFailureBoundary::Preparation,
            'before_activation' => DeploymentFailureBoundary::BeforeActivation,
            default => DeploymentFailureBoundary::Operation,
        };

        return new self(DeploymentResult::failed(null, null, $boundary,
            $status === 'cancelled' ? 'deployment.cancelled' : $code, commit: $commit), $payload['warnings'], $payload['output_tail'] ?? '', $step, $unknown ? 'unknown' : null);
    }

    public function deployment(): DeploymentResult
    {
        return $this->result;
    }

    /** @return array{deployment_id:int,release:?string,selected_release:?string,commit:?string,output_tail?:string,failed_step?:string,step_outcome?:string} */
    public function publicResult(int $deploymentId): array
    {
        return ['deployment_id' => $deploymentId, 'release' => $this->result->release?->name,
            'selected_release' => $this->result->selectedRelease?->name, 'commit' => $this->result->commit,
            ...($this->outputTail === '' ? [] : ['output_tail' => $this->outputTail]),
            ...($this->stepOutcome === null || $this->failedStep === null ? [] : ['failed_step' => $this->failedStep, 'step_outcome' => $this->stepOutcome])];
    }

    private static function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'The native deployment result does not match its admitted Instance and release.', 422);
    }
}
