<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Validator;

/**
 * Records a `fleet.agent_tools` run (ADR 0255) on its Operation: each host's tools with their version before and after, in Node
 * order, and the router's release before and after. A host the run did not reach, or a router without a record, is `failed`.
 */
final readonly class AgentToolsProjection implements DirectOperationProjection
{
    public const array HostOutcomes = ['updated', 'current', 'failed'];

    public const array RouterOutcomes = ['updated', 'current', 'rolled_back', 'failed'];

    private const array HostFields = ['node_id', 'name', 'outcome', 'error_code', 'tools'];

    private const array ToolFields = ['name', 'method', 'before', 'after', 'outcome', 'error'];

    private const array RouterFields = ['node_id', 'name', 'outcome', 'error_code', 'step', 'before', 'latest', 'after', 'pruned'];

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets'] ?? []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $reported = is_array($raw['hosts'] ?? null) ? array_map(ValidatedData::object(...), array_values($raw['hosts'])) : [];
        $router = is_array($raw['router'] ?? null) ? ValidatedData::object($raw['router']) : null;
        $valid = Validator::make(['hosts' => $reported, 'router' => $router], [
            'hosts.*.node_id' => ['required', 'integer', 'distinct'],
            'hosts.*.outcome' => ['required', 'in:'.implode(',', self::HostOutcomes)],
            'hosts.*.error_code' => ['nullable', 'string', 'max:100'],
            'hosts.*.tools' => ['present', 'array'],
            'router.outcome' => ['required_with:router', 'in:'.implode(',', self::RouterOutcomes)],
            'router.error_code' => ['nullable', 'string', 'max:100'],
        ]);
        if ($valid->fails()) {
            throw new ResourceOperationException('operation.result_invalid', 'Each host and the router need one known outcome.', 422);
        }
        $byNode = [];
        foreach ($reported as $host) {
            $host = array_intersect_key($host, array_flip(self::HostFields));
            $host['tools'] = array_map(static fn (mixed $tool): array => array_intersect_key(ValidatedData::object($tool), array_flip(self::ToolFields)),
                array_values(is_array($host['tools']) ? $host['tools'] : []));
            $byNode[ValidatedData::integer($host['node_id'])] = $host;
        }
        $intent = ValidatedData::object($operation->input['intent'] ?? []);
        $hosts = [];
        foreach (is_array($intent['hosts'] ?? null) ? $intent['hosts'] : [] as $target) {
            $target = ValidatedData::object($target);
            $id = ValidatedData::integer($target['node_id']);
            // An unreachable host, or one whose phase was cut, wrote no record.
            $hosts[] = $byNode[$id] ?? ['node_id' => $id, 'name' => $target['name'] ?? null, 'outcome' => 'failed',
                'error_code' => 'fleet.agent_tools_host_unreachable', 'tools' => []];
        }
        $routerIntent = is_array($intent['router'] ?? null) ? ValidatedData::object($intent['router']) : null;
        $routerResult = $routerIntent === null ? null : ($router === null
            ? ['node_id' => $routerIntent['node_id'] ?? null, 'name' => $routerIntent['name'] ?? null, 'outcome' => 'failed', 'error_code' => 'fleet.agent_tools_router_failed']
            : array_intersect_key($router, array_flip(self::RouterFields)));
        $failure = array_find([...$hosts, ...($routerResult === null ? [] : [$routerResult])],
            static fn (array $part): bool => in_array($part['outcome'] ?? null, ['failed', 'rolled_back'], true));
        $result = ['hosts' => $hosts, 'router' => $routerResult,
            ...($failure !== null ? ['error_code' => is_string($failure['error_code'] ?? null) ? $failure['error_code'] : 'fleet.agent_tools_failed'] : [])];

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
