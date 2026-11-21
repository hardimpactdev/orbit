<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\StepSpec;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;

/**
 * `instance.finalize` on the engine ({@see InstanceFinalize}): with a branch selection, `fetch` (`instance.fetch_branch`) brings the
 * selected branch into the Node's mirror; then `names` (`instance.names`) moves the copy's branch, writes its names and restarts T3
 * until it shows its new label.
 */
final readonly class InstanceFinalizeEngineFlow implements EngineDefinition
{
    public function __construct(private InstanceFinalize $finalize) {}

    public function steps(Operation $operation): array
    {
        $node = ValidatedData::integer($operation->node_id);
        $id = (string) ValidatedData::integer($operation->target_id);
        $switch = self::branch($operation)['mode'] === 'switch';

        return [
            ...($switch ? [new StepSpec('fetch', $node, 'instance.fetch_branch', "{$node}:fetch-{$id}", budgetMs: 200_000, resendMs: 60_000)] : []),
            new StepSpec('names', $node, 'instance.names', $id, $switch ? ['fetch'] : [], budgetMs: (InstanceFinalize::LabelSeconds + 90) * 1000, resendMs: 45_000),
        ];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        $container = ValidatedData::object($operation->input['container'] ?? []);
        $branch = self::branch($operation);
        $selection = ValidatedData::object($branch['selection'] ?? []);
        // The selected branch the mirror holds: a new branch starts at its base, an existing one at itself.
        $fetched = ValidatedData::nullableString($selection['base'] ?? null) ?? ValidatedData::nullableString($selection['target'] ?? null) ?? '';

        return match ($step) {
            'fetch' => ['mirror' => $container['mirror'], 'repository' => $operation->input['repository'], 'branch' => $fetched, 'protocol' => $container['protocol'],
                'credential' => ['blob' => ['content' => app(InstanceGitHubReadTokens::class)->line($operation)]]],
            'names' => ['project' => $container['project'], 'name' => $container['name'], 'instance_id' => ValidatedData::integer($operation->target_id),
                'workspace' => $container['workspace'], 'group' => $container['group'],
                'hostname' => ValidatedData::object($operation->input['machine_name'])['hostname'], 'pretty' => ValidatedData::object($operation->input['machine_name'])['pretty'],
                'instance_file' => $operation->input['instance_file'],
                'branch' => $branch['mode'] === 'rename' ? ['mode' => 'rename', 'from' => $branch['from'], 'to' => $branch['to']]
                    : ['mode' => 'switch', 'branch' => $selection['target'], 'fetched' => $fetched, 'mirror' => $container['mirror'],
                        'expected_commit' => self::fetched($run) ?? ValidatedData::string($selection['source_sha'] ?? null)],
                't3_unit' => InstanceContainer::T3Unit, 't3_port' => InstanceHostIntent::T3Port, 'label_seconds' => InstanceFinalize::LabelSeconds],
            default => throw new LogicException("Step [{$step}] is not a finalize step."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException("Step [{$step}] runs on a Node.");
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $names = json_decode($run->output('names') ?? 'null', true);
        $this->finalize->close($operation, $run->succeeded() && is_array($names) ? ValidatedData::object($names) : null,
            $failed === null ? null : ($failed->error_code ?? 'instance.finalize_failed'), $failed?->name);
    }

    /** @return array<string, mixed> */
    private static function branch(Operation $operation): array
    {
        return ValidatedData::object($operation->input['branch'] ?? []);
    }

    /** The commit the fetch brought into the mirror, or null when it fetched nothing. */
    private static function fetched(EngineRun $run): ?string
    {
        $output = json_decode($run->output('fetch') ?? 'null', true);

        return is_array($output) && is_string($output['commit'] ?? null) ? $output['commit'] : null;
    }
}
