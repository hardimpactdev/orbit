<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;
use Symfony\Component\Yaml\Yaml;

/** @return array<string,mixed> one job of a workflow, by name */
function refreshJob(string $workflow, string $job): array
{
    $jobs = Yaml::parseFile(dirname(base_path(), 2)."/swamp/workflows/{$workflow}.yaml")['jobs'];

    return collect($jobs)->firstWhere('name', $job);
}

it('runs the steps of the chart in order, each only after the one before passed, and rolls the base VM back after a failure', function (): void {
    $steps = refreshJob('workflow-operation-task-vm-image-refresh', 'refresh')['steps'];

    expect(array_column($steps, 'name'))->toBe(['Start the base VM', 'Converge', 'Stop and snapshot', 'Prove the candidate', 'Prune old snapshots']);
    $after = static fn (string $step): array => ['step' => $step, 'condition' => ['type' => 'succeeded']];
    expect($steps[1]['dependsOn'])->toBe([$after('Start the base VM')])
        ->and($steps[2]['dependsOn'])->toBe([$after('Converge')])
        ->and($steps[3]['dependsOn'])->toBe([$after('Stop and snapshot')])
        ->and($steps[4]['dependsOn'])->toBe([$after('Prove the candidate')]);
    expect(refreshJob('workflow-operation-task-vm-image-refresh', 'discard'))
        ->toMatchArray(['dependsOn' => [['job' => 'refresh', 'condition' => ['type' => 'failed']]]])
        ->and(refreshJob('workflow-operation-task-vm-image-refresh', 'discard')['steps'][0]['task']['inputs']['tags'])->toBe('base-discard');
});

it('logs the egress of the host bridge before the base is touched, so a failure there starts no rollback', function (): void {
    $egress = refreshJob('workflow-operation-task-vm-image-refresh', 'egress');
    $refresh = refreshJob('workflow-operation-task-vm-image-refresh', 'refresh');

    expect(array_column($egress['steps'], 'name'))->toBe(['Log egress'])
        ->and($egress['steps'][0]['task']['workflowIdOrName'])->toBe('task-vm-host-phase')
        ->and($egress['steps'][0]['task']['inputs']['tags'])->toBe('egress-log')
        ->and($refresh['dependsOn'])->toBe([['job' => 'egress', 'condition' => ['type' => 'succeeded']]])
        // The rollback follows the refresh job only: a failed egress part skips the refresh, and the base stays as it was.
        ->and(refreshJob('workflow-operation-task-vm-image-refresh', 'discard')['dependsOn'])->toBe([['job' => 'refresh', 'condition' => ['type' => 'failed']]]);
});

it('places the agent delivery of the checkout on the nested Gateway before its Swamp tokens are renewed and its code migrates', function (): void {
    $steps = collect(refreshJob('workflow-task-nested-apply', 'apply')['steps'])->keyBy('name');
    $checkout = '/home/orbit/orbit/';

    expect($steps['Place the agent delivery']['task']['inputs']['argv'])
        ->toContain($checkout.GatewayReleaseAgentDelivery::Pin, $checkout.GatewayReleaseAgentDelivery::Manifest)
        ->and(array_column($steps['Re-mint the Swamp tokens']['dependsOn'], 'step'))->toBe(['Place the agent delivery'])
        ->and($steps['Migrate']['dependsOn'])->toContain(['step' => 'Place the agent delivery', 'condition' => ['type' => 'succeeded']]);
});

it('carries the error of a failed Ansible run to the Operation: the run keeps its output and an assert fails with its stderr', function (string $workflow, string $run, string $check): void {
    $steps = collect(refreshJob($workflow, match ($workflow) {
        'workflow-task-vm-image-ansible' => 'converge',
        'workflow-task-vm-start' => 'start',
        default => 'phase',
    })['steps'])->keyBy('name');

    expect($steps[$run]['task']['inputs']['ignoreExitCode'])->toBeTrue()
        ->and($steps[$run]['task']['inputs']['env'])->toMatchArray(['ANSIBLE_DISPLAY_FAILED_STDERR' => 'true', 'ANSIBLE_STDOUT_CALLBACK' => 'community.general.timestamp'])
        ->and($steps[$check]['task']['type'])->toBe('assert')
        ->and($steps[$check]['task']['expr'])->toBe('steps["'.$run.'"].outputs.exitCode == 0')
        ->and($steps[$check]['task']['message'])->toContain('steps["'.$run.'"].outputs.stderr');
})->with([
    ['workflow-task-vm-image-ansible', 'Run the Ansible phases', 'Check the Ansible phases'],
    ['workflow-task-vm-start', 'Run the Ansible phases', 'Check the Ansible phases'],
    ['workflow-task-vm-host-phase', 'Run the host phase', 'Check the host phase'],
]);

it('makes the base VM of a new host once, from the stock image and a blank seed, before it starts it', function (): void {
    $plays = Yaml::parseFile(dirname(base_path(), 2).'/swamp/ansible/task-vm-host.yml');
    $start = collect(collect($plays)->firstWhere('name', 'Task VM base')['tasks'])->firstWhere('name', 'Start the base VM');
    $steps = collect($start['block'])->keyBy('name');
    $make = collect($steps['Make the base VM']['block'])->keyBy('name');

    expect($start['tags'])->toBe(['base-start'])
        // A rebuild (`--from-stock`) deletes the base VM and its seed first; a refresh skips it.
        ->and(array_keys($steps->all()))->toBe(['Delete the base VM and its seed for a rebuild', 'Read the base VM', 'Make the base VM', 'Attach the seed', 'Read the snapshot rule of the project', 'Let the project keep snapshots',
            'Skip the firmware boot menu wait', 'Start the base VM', 'Wait for its guest agent', 'Wait for the first boot', 'Give the base VM the Club key'])
        ->and($steps['Delete the base VM and its seed for a rebuild']['when'])->toBe('host.rebuild | default(false)')
        ->and($steps['Make the base VM']['when'])->toBe('base_read.stdout | from_json | length == 0')
        ->and(array_keys($make->all()))->toBe(['Remove the seed a failed creation left', 'Create the blank seed volume', 'Create the base VM'])
        ->and($make['Create the blank seed volume']['ansible.builtin.command']['argv'])->toContain('base.seed_size', '--type')
        ->and($make['Create the base VM']['ansible.builtin.command']['argv'])->toContain('host.image', 'base.vm')
        ->and($make['Create the base VM']['ansible.builtin.command']['stdin'])->toBe('{{ base.launch_config }}');
});

it('starts a task VM and converges it in one Ansible run: the host part and the role come from one playbook, with the converge part of the intent for the inventory', function (): void {
    $steps = collect(refreshJob('workflow-task-vm-start', 'start')['steps'])->keyBy('name');
    $run = $steps['Run the Ansible phases']['task']['inputs'];
    $plays = Yaml::parseFile(dirname(base_path(), 2).'/swamp/ansible/task-vm-start.yml');

    expect(array_column($plays, 'ansible.builtin.import_playbook'))->toBe(['task-vm-host.yml', 'task-vm-role.yml'])
        ->and(substr_count($run['run'], 'ansible-playbook'))->toBe(1)
        ->and($run['run'])->toContain('build_vars.py')
        ->and($run['env']['ORBIT_INTENT_KEY'])->toBe('converge')
        ->and(explode(',', $run['env']['ORBIT_TAGS']))->toContain('start', 'software', 'check', 'branch')
        // The old two-step start and the converge's own direct path are gone.
        ->and(file_exists(dirname(base_path(), 2).'/swamp/workflows/workflow-task-vm-slot-start.yaml'))->toBeFalse()
        ->and(file_get_contents(dirname(base_path(), 2).'/swamp/workflows/workflow-task-vm-image-ansible.yaml'))->not->toContain('intent.direct');
});
