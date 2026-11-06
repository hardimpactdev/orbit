<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectMaintenanceProjection;
use App\Domain\Tasks\TaskGitHubTokens;
use App\Domain\Tasks\TaskPullRequestMergeProjection;

return [
    'project.observe' => ['workflow' => 'project-observe', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'observe', 'step' => 'Refresh the findings index', 'native' => true,
            'captures' => ['maintenance' => ['job' => 'observe', 'step' => 'Refresh the findings index', 'field' => 'result']]],
        'projection' => ProjectMaintenanceProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^project-dependency-read-[1-9][0-9]*$']]],
    // project:maintenance:inspect: the update steps run only when something changed or the check is due.
    'project.detect' => ['workflow' => 'project-maintenance-inspect', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'inspect', 'step' => 'Find the updates', 'alternatives' => ['Read the manifest SHAs'], 'native' => true, 'captures' => [
            'manifests' => ['job' => 'inspect', 'step' => 'Select the changed sources'],
            'sources' => ['job' => 'inspect', 'step' => 'List the packages', 'field' => 'sources'],
            'registries' => ['job' => 'inspect', 'step' => 'Check latest, advisories', 'field' => 'checked_at'],
            'updates' => ['job' => 'inspect', 'step' => 'Find the updates'],
        ]], 'projection' => ProjectMaintenanceProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^project-dependency-read-[1-9][0-9]*$']]],
    // project:pr-merge: the same Swamp run as tasks:pr-merge, for a pull request that no Task group watches.
    'project.pr_merge' => ['workflow' => 'operation-task-pr-merge', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => TaskPullRequestMergeProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the merge', 'native' => true, 'captures' => ['pr_receipt' => ['job' => 'merge', 'step' => 'Squash on expected head', 'field' => 'stdout']]],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-merge-write-[a-f0-9-]{36}$']]],
    'project.routine' => ['workflow' => 'project-routine', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'routine', 'step' => "Select this week's routine", 'native' => true,
            'captures' => ['maintenance' => ['job' => 'routine', 'step' => "Select this week's routine", 'field' => 'result']]],
        'projection' => ProjectMaintenanceProjection::class],
];
