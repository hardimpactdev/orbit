<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Models\Operation;

/** Validates bounded native facts and derives history data; performs no execution or receipt lookup. */
final readonly class InstanceNativeDeploymentResult
{
    private function __construct(private DeploymentResult $result, public int $warnings) {}

    /** @param array<string,mixed> $payload */
    public static function from(Operation $operation, string $status, ?string $code, array $payload): self
    {
        $keys = ['instance_id', 'commit', 'release', 'previous', 'selected_release', 'selection_confirmed', 'failed_step', 'warnings'];
        if ($operation->kind !== 'instance.deploy' || $operation->target_type !== 'instance'
            || count($payload) !== count($keys) || array_diff($keys, array_keys($payload)) !== []
            || ($payload['instance_id'] ?? null) !== $operation->target_id
            || ! in_array($status, ['succeeded', 'failed', 'cancelled'], true)
            || ($status === 'failed' ? ! is_string($code) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1 : $code !== null)
            || ! is_bool($payload['selection_confirmed']) || ! is_int($payload['warnings'])
            || $payload['warnings'] < 0 || $payload['warnings'] > 64) {
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
            $status === 'cancelled' ? 'deployment.cancelled' : $code, commit: $commit), $payload['warnings']);
    }

    public function deployment(): DeploymentResult
    {
        return $this->result;
    }

    /** @return array{deployment_id:int,release:?string,selected_release:?string,commit:?string} */
    public function publicResult(int $deploymentId): array
    {
        return ['deployment_id' => $deploymentId, 'release' => $this->result->release?->name,
            'selected_release' => $this->result->selectedRelease?->name, 'commit' => $this->result->commit];
    }

    private static function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'The native deployment result does not match its admitted Instance and release.', 422);
    }
}
