<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** One bounded host cache fill. Project usage is projected only from its proven, redacted receipt. */
final readonly class TaskVmPackageCacheCommand implements OperationEffectTransport, OperationTransportInput
{
    use TaskVmTargetReceipt;
    use TaskVmTransportSchema;

    public function __construct(private TaskVmPackageCache $cache, private TaskVmSettings $settings) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        if ($operation->node_id !== ($input['host_node_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The cache host does not match this Operation.', 422);
        }
        $node = Node::query()->findOrFail($operation->node_id);
        $result = $this->cache->fillProven($this->settings->host($node->id), $node, TaskVmInputs::artifacts($input['artifacts']), ValidatedData::nullableString($input['github_token']), ValidatedData::integer($input['max_bytes']), $run);
        $result['failures'] += ValidatedData::object($input['conflicts']);

        return ['ready' => true, 'archive_ids' => array_keys($result['hashes']), 'archive_digests' => array_values($result['hashes']),
            'failed_ids' => array_keys($result['failures']), 'failure_reasons' => array_values($result['failures'])];
    }

    /** @param array<string,mixed> $run */
    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array{effect_key:string,state:string,result:array<string,mixed>|null,sanitized:bool,effect_nonce:string|null,effect_created_at:int|null,exit_code:int|null,finished_at:string|null}
     */
    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $receipt = $this->targetReceipt(Node::query()->findOrFail($operation->node_id), 'package-cache.host', ValidatedData::string($run['effect_key'] ?? null));
        if ($receipt['state'] === 'succeeded' && $receipt['result'] !== null) {
            $result = TaskVmPackageCache::verifiedResult(TaskVmInputs::artifacts($input['artifacts']), $receipt['result']);
            $result['failures'] += ValidatedData::stringMap($input['conflicts']);
            $receipt['result'] = ['ready' => true, 'archive_ids' => array_keys($result['hashes']), 'archive_digests' => array_values($result['hashes']), 'failed_ids' => array_keys($result['failures']), 'failure_reasons' => array_values($result['failures'])];
        }

        return $receipt;
    }

    public function wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed
    {
        $input['conflicts'] = (object) ValidatedData::object($input['conflicts']);

        return json_decode(json_encode((object) $input, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    }
}
