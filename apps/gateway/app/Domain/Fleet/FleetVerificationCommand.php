<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Support\ValidatedData;

/** Gateway-local read primitive. Never applies a change on a Node. */
final readonly class FleetVerificationCommand implements OperationGatewayCommand
{
    public function __construct(private FleetNodeVerification $doctor, private AgentDelivery $delivery, private ToolInspector $tools, private VersionConstraint $constraints, private FleetFootprintChild $children) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['mode' => ['type' => 'string', 'enum' => ['recipe', 'rollout']]], 'required' => ['mode'], 'additionalProperties' => false];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $node = Node::query()->with('roles')->findOrFail($operation->node_id);
        if ($input['mode'] === 'recipe') {
            foreach (Tool::query()->with(['node', 'manager'])->where('node_id', $node->id)->orderBy('id')->get() as $tool) {
                $observed = $this->tools->inspect($tool);
                if (! $observed->installed || ($tool->version_constraint !== null
                    && ($observed->normalizedVersion === null || ! $this->constraints->isValid($tool->version_constraint)
                        || ! $this->constraints->allows($observed->normalizedVersion, $tool->version_constraint)))) {
                    return ['passed' => false, 'error_code' => 'node.recipe_inventory_failed'];
                }
            }
        }
        $footprint = $input['mode'] === 'rollout' ? ($this->children->succeeded($operation)->result ?? []) : [];
        $tolerated = $this->children->caddySkip($footprint) === null ? [] : ['role.caddy_build_drift'];
        $changed = false;
        $cliVersion = null;
        if ($input['mode'] === 'rollout') {
            foreach (ValidatedData::object($operation->state['executor_runs'] ?? []) as $run) {
                if (! is_array($run) || ($run['transport'] ?? null) !== 'agent' || ! is_array($run['result'] ?? null) || isset($run['result']['error_code'])) {
                    continue;
                }
                $result = $run['result'];
                if ($run['name'] === 'cli.install') {
                    $changed = $changed || is_string($result['installed'] ?? null) || ($result['configured'] ?? false) === true;
                    $cliVersion = $result['installed'] ?? $cliVersion;
                } elseif ($run['name'] === 'cli.self-update' && in_array($result['outcome'] ?? null, ['updated', 'unchanged'], true)) {
                    $changed = $changed || $result['outcome'] === 'updated';
                    $cliVersion = $result['cli_version'] ?? $cliVersion;
                }
            }
            $artifacts = $footprint['artifacts'] ?? [];
            if (! is_array($artifacts) || ! array_is_list($artifacts)) {
                throw new \LogicException('Footprint artifacts must be a declared list.');
            }
            foreach ($artifacts as $artifact) {
                $changed = $changed || (ValidatedData::object($artifact)['status'] ?? null) === 'applied';
            }
        }
        $baseline = ValidatedData::stringList($operation->input['baseline'] ?? []);
        $report = $this->doctor->verify($node, $baseline, $this->delivery->reportedVersion($node), $tolerated);

        return ['passed' => $report['passed'], ...($input['mode'] === 'rollout' ? ['changed' => $changed, 'cli_version' => $cliVersion ?? (ValidatedData::object($operation->input['release'] ?? [])['version'] ?? null), 'caddy_skipped' => $tolerated !== []] : []), 'agent_version' => $report['agent_version'], 'presence' => $report['presence'],
            'error_code' => $report['passed'] ? null : ($input['mode'] === 'recipe' ? 'node.recipe_doctor_failed' : 'fleet.verify_failed')];
    }
}
