<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Routes\RouteExecutionProof;
use App\Support\ValidatedData;
use Symfony\Component\Yaml\Yaml;

/**
 * The record-only removal of a never-converged role relies on this: a run that fails at one of
 * NodeRoleDirectProjection::StepsBeforeNodeChange has changed nothing on the target Node. The
 * workflow is the other half of that rule, so this test reads it.
 *
 * @return array{steps: array<string, array<string,mixed>>, jobOf: array<string,string>, jobs: array<string, array<string,mixed>>}
 */
function roleAddWorkflow(): array
{
    $workflow = ValidatedData::object(Yaml::parseFile(base_path('../../swamp/workflows/workflow-operation-node-role-add.yaml')));
    $steps = [];
    $jobOf = [];
    $jobs = [];
    foreach (RouteExecutionProof::objects($workflow['jobs']) as $job) {
        $jobName = ValidatedData::string($job['name']);
        $jobs[$jobName] = $job;
        foreach (RouteExecutionProof::objects($job['steps']) as $step) {
            $stepName = ValidatedData::string($step['name']);
            $steps[$stepName] = $step;
            $jobOf[$stepName] = $jobName;
        }
    }

    return ['steps' => $steps, 'jobOf' => $jobOf, 'jobs' => $jobs];
}

/** @return list<string> */
function stepsBeforeNodeChange(): array
{
    return ValidatedData::stringList((new ReflectionClassConstant(NodeRoleDirectProjection::class, 'StepsBeforeNodeChange'))->getValue());
}

/**
 * Every job that must have succeeded before this job starts, through `succeeded` edges only. A
 * `completed` or `skipped` edge also starts a job after a failure or a skip, so it proves nothing.
 *
 * @param  array<string, array<string,mixed>>  $jobs
 * @return list<string>
 */
function succeededAncestors(array $jobs, string $job): array
{
    $found = [];
    $queue = [$job];
    while ($queue !== []) {
        $current = array_pop($queue);
        foreach (RouteExecutionProof::objects($jobs[$current]['dependsOn'] ?? []) as $edge) {
            $upstream = ValidatedData::string($edge['job']);
            if (ValidatedData::object($edge['condition'])['type'] === 'succeeded' && ! in_array($upstream, $found, true)) {
                $found[] = $upstream;
                $queue[] = $upstream;
            }
        }
    }

    return $found;
}

/**
 * Whether a step with `writes: true` acts on the target Node. The rule is conservative: every step counts,
 * including a step of a type this test does not know, except two kinds that touch only the run folder
 * on the Swamp Node:
 *
 * - `@nckrtl/ansible-input` steps, which write or compose the intent and playbook input;
 * - `command/shell` steps that do not run `ansible-playbook`: the private input files, the
 *   `build_vars.py` calls and the final run-folder `cleanup` (`rm -rf`).
 *
 * The jobs `intent`, `private-*` and `input` come before the two read-only steps and write only that
 * folder, so a failure at either read-only step has run them already. The final `cleanup` job runs after any
 * result and removes the run folder only. None of them changes the target Node, so none counts.
 *
 * @param  array<string,mixed>  $step
 */
function actsOnTargetNode(array $step): bool
{
    $task = ValidatedData::object($step['task']);
    if (($task['behaviorType'] ?? null) === '@nckrtl/ansible-input') {
        return false;
    }
    if (($task['behaviorType'] ?? null) === 'command/shell' && ! str_contains(ValidatedData::string(ValidatedData::object($task['inputs'])['run']), 'ansible-playbook')) {
        return false;
    }

    return true;
}

it('names steps that exist in the role add workflow', function (): void {
    $workflow = roleAddWorkflow();

    foreach (stepsBeforeNodeChange() as $name) {
        expect($workflow['steps'])->toHaveKey($name);
    }
});

it('keeps every step before the first change read-only', function (): void {
    $workflow = roleAddWorkflow();

    foreach (stepsBeforeNodeChange() as $name) {
        expect($workflow['steps'][$name]['writes'] ?? false)->not->toBeTrue("[$name] must not write");
    }
});

it('runs every step that acts on the target Node only after the read-only steps succeeded', function (): void {
    $workflow = roleAddWorkflow();
    $required = array_map(static fn (string $name): string => $workflow['jobOf'][$name], stepsBeforeNodeChange());
    $counted = [];

    foreach ($workflow['steps'] as $name => $step) {
        if (($step['writes'] ?? false) !== true || ! actsOnTargetNode($step)) {
            continue;
        }
        $counted[] = $name;
        $job = $workflow['jobOf'][$name];
        $missing = array_diff($required, succeededAncestors($workflow['jobs'], $job));

        expect($missing)->toBe([], "[$name] in job [$job] does not wait for the succeeded job(s): ".implode(', ', $missing));
    }

    // The rule must not be empty: the steps that change the Node, and the init-container cleanup, are counted.
    expect($counted)->toContain('Apply the role on the Node')->toContain('Install Python venv')->toContain('Remove init containers');
    expect(array_intersect($counted, ['Write the intent', 'Remove the playbook input']))->toBe([]);
});
