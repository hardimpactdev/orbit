<?php

declare(strict_types=1);

use App\Domain\Tasks\DependencyMergeProjection;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskCopyTurnProjection;
use App\Domain\Tasks\TaskGitHubTokens;
use App\Domain\Tasks\TaskIdleSaveProjection;
use App\Domain\Tasks\TaskMaintenanceProjection;
use App\Domain\Tasks\TaskPackageProjection;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\Tasks\TaskPublishProjection;
use App\Domain\Tasks\TaskPullRequestMergeProjection;
use App\Domain\Tasks\TaskPullRequestReadyProjection;
use App\Domain\Tasks\TaskScriptProjection;
use App\Domain\Tasks\TaskSyncProjection;
use App\Domain\Tasks\TaskTurnProjection;

// What a task start writes onto its new VM (docs/cli/task/start.mmd): the slot's identity, and the claim with its Pi token and the Pi
// models file that holds the fixed model key. The `swamp-proof-club-api-key` alternative was written only by the removed image refresh;
// the pattern stays as it is until the grants release after the Pi loop (S8), so the grants do not change before then.
$claimCopies = [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(swamp-proof-club-api-key|task-vm-slot-(wireguard|ssh-host|agent)-[1-9][0-9]*)$'],
    ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-pi-(claim|token)-[1-9][0-9]*$']];
// The vault keys that only the removed prep fix, build and review round declared (docs/reference/old-paths.md): the Pi token of a
// resumed VM and the comment token of a round. Nothing writes them any more. The patterns stay declared, byte-equal, until the grants
// release after the Pi loop (S8), so the grants do not change before then.
$retiredPiLoopCopies = [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-pi-token-[1-9][0-9]*$'],
    ['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-comment-write-[a-f0-9-]{36}$']];

return ['tasks.maintenance' => ['workflow' => 'operation-task-maintenance', 'projection' => TaskMaintenanceProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'maintenance', 'step' => 'Check the result', 'native' => true, 'captures' => ['maintenance' => ['job' => 'maintenance', 'step' => 'shape', 'field' => 'result_json', 'artifact' => 'output']]]], 'tasks.maintenance_finish' => ['workflow' => 'operation-task-cancel', 'projection' => TaskCancelProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the end', 'native' => true]], 'tasks.cancel' => ['workflow' => 'operation-task-cancel', 'projection' => TaskCancelProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the end', 'native' => true]], 'tasks.provision' => [
    'workflow' => 'operation-task-provision', 'projection' => TaskProvisionProjection::class,
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'provision', 'step' => TaskProvisionProjection::EndCheck, 'native' => true],
    // The slot's identity and the claim with its Pi token and models file, which the task start writes onto its new VM.
    'execution_copies' => $claimCopies,
], 'tasks.package' => ['workflow' => 'operation-task-package', 'projection' => TaskPackageProjection::class, 'github_token_issuer' => TaskGitHubTokens::class, 'workflow_inputs' => true,
    'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the package', 'native' => true,
        'captures' => ['dependency_token' => ['job' => 'cleanup', 'step' => 'Remove the dependency auth', 'field' => 'result']]],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-provision-deps-(composer|npm|token)-[1-9][0-9]*$']],
], 'tasks.idle_save' => ['workflow' => 'operation-task-idle-save', 'projection' => TaskIdleSaveProjection::class, 'github_token_issuer' => TaskGitHubTokens::class, 'workflow_inputs' => true,
    'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the save', 'native' => true, 'captures' => ['saved' => ['job' => 'save', 'step' => 'Read the saved commit', 'field' => 'stdout']]],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-token-[a-f0-9-]{36}$']],
], 'tasks.publish' => ['workflow' => 'operation-task-publish', 'projection' => TaskPublishProjection::class, 'github_token_issuer' => TaskGitHubTokens::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm publication', 'native' => true, 'captures' => ['pull_receipt' => ['job' => 'publish', 'step' => 'Read PR result', 'field' => 'stdout', 'artifact' => 'output'], 'base' => ['job' => 'publish', 'step' => 'Check the base', 'field' => 'stdout', 'artifact' => 'output']]], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-token-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-environment-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^nested-fleet-club-api-key$']]], 'tasks.check' => ['workflow' => 'operation-task-check', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => TaskCheckProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the result', 'native' => true,
    'captures' => ['receipt' => ['job' => 'check', 'step' => 'Push the candidate', 'field' => 'finish'], 'instance_receipt' => ['job' => 'check', 'step' => 'Check in the Instance', 'field' => 'finish']]],
    'scheduled_result' => ['workflow' => 'task-check-ci', 'workflow_id' => '4a2d9dd5-9f83-41d4-b8df-8e46367ed230',
        'terminal_result' => ['job' => 'checks', 'step' => 'collect', 'data' => 'task-check-ci-data'], 'rows' => 'checks'], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-write-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-read-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-git-[a-f0-9-]{36}$']]], 'tasks.script' => ['workflow' => 'operation-task-script', 'projection' => TaskScriptProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'script', 'step' => 'outcome', 'native' => true, 'captures' => ['program' => ['job' => 'script', 'step' => 'shape', 'field' => 'result_json', 'artifact' => 'output']]]], 'tasks.copy_turn' => ['workflow' => 'operation-task-copy-turn', 'projection' => TaskCopyTurnProjection::class, 'workflow_inputs' => true,
            'terminal_result' => ['job' => 'turn', 'step' => 'outcome', 'native' => true, 'captures' => ['turn' => ['job' => 'turn', 'step' => 'result', 'field' => 'turn']]]], 'tasks.turn' => ['workflow' => 'operation-task-turn', 'projection' => TaskTurnProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'turn', 'step' => 'outcome', 'native' => true, 'captures' => ['turn' => ['job' => 'turn', 'step' => 'shape_result', 'field' => 'result_json', 'artifact' => 'result']]], 'execution_copies' => [...$claimCopies, ...$retiredPiLoopCopies]], 'tasks.dependency_merge' => [
                'workflow' => 'operation-dependency-merge', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => DependencyMergeProjection::class,
                'workflow_inputs' => true,
                'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the merge', 'native' => true, 'captures' => ['merge_receipt' => ['job' => 'squash', 'step' => 'Read merge result', 'field' => 'stdout', 'artifact' => 'output']]],
                'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^dependency-merge-(read|write)-[a-f0-9-]{36}$']],
            ], 'tasks.pr_ready' => [
                'workflow' => 'operation-task-pr-ready', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => TaskPullRequestReadyProjection::class,
                'workflow_inputs' => true,
                'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the ready step', 'native' => true, 'captures' => ['pr_receipt' => ['job' => 'ready', 'step' => 'Read the PR result', 'field' => 'stdout', 'artifact' => 'result']]],
                'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-ready-write-[a-f0-9-]{36}$']],
            ], 'tasks.pr_merge' => [
                'workflow' => 'operation-task-pr-merge', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => TaskPullRequestMergeProjection::class,
                'workflow_inputs' => true,
                'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the merge', 'native' => true, 'captures' => ['pr_receipt' => ['job' => 'merge', 'step' => 'Squash on expected head', 'field' => 'stdout', 'artifact' => 'result']]],
                'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-merge-write-[a-f0-9-]{36}$']],
            ], 'tasks.sync' => [
                'workflow' => 'operation-task-sync', 'alternate_workflows' => ['operation-task-sync-copy'], 'projection' => TaskSyncProjection::class,
                'workflow_inputs' => true,
                // A task copy's run captures the base phase's typed evidence instead of the refs.
                'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the sync', 'native' => true, 'captures' => ['refs' => ['job' => 'sync', 'step' => 'Read the refs', 'field' => 'stdout', 'artifact' => 'output'],
                    'base' => ['job' => 'sync', 'step' => 'Bring in the base', 'field' => 'evidence']]],
                'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-sync-token-[a-f0-9-]{36}$']],
            ]];
