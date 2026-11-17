<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class ConnLaunchNativeProjection implements DirectOperationProjection
{
    public const string Vault = 'orbit-control-inputs';

    public function __construct(
        private DirectOperation $direct,
        private SwampCommandStep $swamp,
        private SwampSshExecutionInput $ssh,
        private ConnLaunchDispatchSource $source,
    ) {}

    public static function executionCopyKey(Operation $operation): string
    {
        return 'conn-launch-dispatch-'.$operation->id;
    }

    public function input(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input['intent']);
        $node = Node::query()->findOrFail($operation->node_id);
        $secrets = [];
        if ($operation->kind === ConnLaunchDispatchSource::Kind) {
            $launchId = ValidatedData::integer($operation->input['launch_operation_id']);
            $source = $this->source->resolve($operation, $launchId, '', ConnLaunchDispatchSource::Field);
            $value = json_encode($source->value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $secrets[ConnLaunchDispatchSource::Field] = $this->swamp->executionCopy(self::Vault, self::executionCopyKey($operation), $value);
        }

        return ['intent' => [...$intent, 'ssh_execution' => $this->ssh->forNode($node)], 'execution_secrets' => $secrets];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = $data['result'] ?? null;
        $inspect = $operation->kind !== ConnLaunchDispatchSource::Kind;
        if (($data['status'] ?? null) !== 'succeeded') {
            return $this->direct->project($operation, $data, []);
        }
        if (! is_array($raw) || ($raw['error_code'] ?? null) !== null) {
            throw new ResourceOperationException('operation.result_invalid', 'The Conn launch run returned no verified receipt.', 422);
        }

        return $this->direct->project($operation, $data, $inspect ? self::preparation($operation, $raw) : self::acceptance($raw));
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private static function preparation(Operation $operation, array $receipt): array
    {
        $intent = ValidatedData::object($operation->input['intent']);
        $expected = $intent['environment_id'] ?? null;
        $incarnation = $receipt['incarnation'] ?? null;
        $project = $receipt['project_id'] ?? null;
        $requestedAvailable = $receipt['requested_provider_available'] ?? null;
        $fallback = $receipt['model_selection'] ?? null;
        if (! is_string($expected) || ($receipt['environment_id'] ?? null) !== $expected
            || ! is_string($incarnation) || preg_match('/\A[0-9a-f]{64}\z/D', $incarnation) !== 1
            || ! is_string($project) || $project === '' || strlen($project) > 255
            || ! is_bool($requestedAvailable)
            || ($requestedAvailable ? $fallback !== null
                : (! is_array($fallback) || ! is_string($fallback['instanceId'] ?? null) || ! is_string($fallback['model'] ?? null)
                    || strlen(json_encode($fallback, JSON_THROW_ON_ERROR)) > 16_384))) {
            throw new ResourceOperationException('operation.result_invalid', 'The Conn launch preparation receipt does not match the registered workspace.', 422);
        }

        return ['environment_id' => $expected, 'incarnation' => $incarnation, 'project_id' => $project,
            'requested_provider_available' => $requestedAvailable, 'model_selection' => $fallback];
    }

    /** @param array<string, mixed> $receipt
     * @return array{accepted_at: string}
     */
    private static function acceptance(array $receipt): array
    {
        $time = $receipt['accepted_at'] ?? null;
        if (! is_string($time) || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\z/D', $time) !== 1) {
            throw new ResourceOperationException('operation.result_invalid', 'The Conn launch run did not confirm first-message acceptance.', 422);
        }

        return ['accepted_at' => $time];
    }
}
