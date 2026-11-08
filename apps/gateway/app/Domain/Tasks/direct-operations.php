<?php

declare(strict_types=1);

use App\Domain\Tasks\DependencyMergeProjection;
use App\Domain\Tasks\TaskBuildProjection;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskGitHubTokens;
use App\Domain\Tasks\TaskMaintenanceProjection;
use App\Domain\Tasks\TaskPackageProjection;
use App\Domain\Tasks\TaskPrepFixProjection;
use App\Domain\Tasks\TaskPrepProjection;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\Tasks\TaskPublishProjection;
use App\Domain\Tasks\TaskPullRequestMergeProjection;
use App\Domain\Tasks\TaskPullRequestReadyProjection;
use App\Domain\Tasks\TaskReviewRoundProjection;
use App\Domain\Tasks\TaskScriptProjection;
use App\Domain\Tasks\TaskSyncProjection;
use App\Domain\Tasks\TaskTurnProjection;

$taskVmKinds = require __DIR__.'/../TaskVms/direct-operations.php';
// What a task start writes onto its new VM; the image refresh proves its candidate with one.
$claimCopies = $taskVmKinds['task_vm.image_refresh']['execution_copies'];
$vmResume = $taskVmKinds['task_vm.resume'];

return ['tasks.maintenance' => ['workflow' => 'operation-task-maintenance', 'projection' => TaskMaintenanceProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'maintenance', 'step' => 'Check the result', 'native' => true, 'captures' => ['maintenance' => ['job' => 'maintenance', 'step' => 'shape', 'field' => 'result_json', 'artifact' => 'output']]]], 'tasks.maintenance_finish' => ['workflow' => 'operation-task-cancel', 'projection' => TaskCancelProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the end', 'native' => true]], 'tasks.cancel' => ['workflow' => 'operation-task-cancel', 'projection' => TaskCancelProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the end', 'native' => true]], 'tasks.provision' => [
    'workflow' => 'operation-task-provision', 'projection' => TaskProvisionProjection::class,
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'provision', 'step' => TaskProvisionProjection::EndCheck, 'native' => true],
    // The slot's identity and the claim with its Pi token and models file, which the task start writes onto its new VM.
    'execution_copies' => $claimCopies,
], 'tasks.package' => ['workflow' => 'operation-task-package', 'projection' => TaskPackageProjection::class, 'github_token_issuer' => TaskGitHubTokens::class, 'workflow_inputs' => true,
    'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the package', 'native' => true,
        'captures' => ['dependency_token' => ['job' => 'cleanup', 'step' => 'Remove the dependency auth', 'field' => 'result']]],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-provision-deps-(composer|npm|token)-[1-9][0-9]*$']],
], 'tasks.publish' => ['workflow' => 'operation-task-publish', 'projection' => TaskPublishProjection::class, 'github_token_issuer' => TaskGitHubTokens::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm publication', 'native' => true, 'captures' => ['pull_receipt' => ['job' => 'publish', 'step' => 'Read PR result', 'field' => 'stdout', 'artifact' => 'output']]], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-token-[a-f0-9-]{36}$']]], 'tasks.check' => ['workflow' => 'operation-task-check', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => TaskCheckProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the result', 'native' => true,
    'captures' => ['receipt' => ['job' => 'check', 'step' => 'Push the candidate', 'field' => 'finish']]],
    'scheduled_result' => ['workflow' => 'task-check-ci', 'workflow_id' => '4a2d9dd5-9f83-41d4-b8df-8e46367ed230',
        'terminal_result' => ['job' => 'checks', 'step' => 'collect', 'data' => 'task-check-ci-data'], 'rows' => 'checks'], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-write-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-read-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-git-[a-f0-9-]{36}$']]], 'tasks.script' => ['workflow' => 'operation-task-script', 'projection' => TaskScriptProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'script', 'step' => 'outcome', 'native' => true, 'captures' => ['program' => ['job' => 'script', 'step' => 'shape', 'field' => 'result_json', 'artifact' => 'output']]]], 'tasks.turn' => ['workflow' => 'operation-task-turn', 'projection' => TaskTurnProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'turn', 'step' => 'outcome', 'native' => true, 'captures' => ['turn' => ['job' => 'turn', 'step' => 'shape_result', 'field' => 'result_json', 'artifact' => 'result']]], 'execution_copies' => $claimCopies], 'tasks.dependency_merge' => [
            'workflow' => 'operation-dependency-merge', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => DependencyMergeProjection::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the merge', 'native' => true, 'captures' => ['merge_receipt' => ['job' => 'squash', 'step' => 'Read merge result', 'field' => 'stdout', 'artifact' => 'output']]],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^dependency-merge-(read|write)-[a-f0-9-]{36}$']],
        ], 'tasks.pr_ready' => [
            'workflow' => 'operation-task-pr-ready', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => TaskPullRequestReadyProjection::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the ready step', 'native' => true, 'captures' => ['pr_receipt' => ['job' => 'ready', 'step' => 'Read the PR result', 'field' => 'stdout', 'artifact' => 'output']]],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-ready-write-[a-f0-9-]{36}$']],
        ], 'tasks.pr_merge' => [
            'workflow' => 'operation-task-pr-merge', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => TaskPullRequestMergeProjection::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the merge', 'native' => true, 'captures' => ['pr_receipt' => ['job' => 'merge', 'step' => 'Squash on expected head', 'field' => 'stdout', 'artifact' => 'output']]],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-merge-write-[a-f0-9-]{36}$']],
        ], 'tasks.review_round' => [
            'workflow' => 'operation-task-review-round', 'projection' => TaskReviewRoundProjection::class, 'github_token_issuer' => TaskGitHubTokens::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'round', 'step' => 'Confirm the round', 'native' => true, 'captures' => [
                'review' => ['job' => 'round', 'step' => 'Review the head'], 'fix' => ['job' => 'round', 'step' => 'Fix the findings'],
                'verdict' => ['job' => 'round', 'step' => 'Settle the review', 'field' => 'verdict', 'artifact' => 'verdict'], 'findings' => ['job' => 'round', 'step' => 'Settle the review', 'field' => 'findings', 'artifact' => 'verdict'],
                'checked' => ['job' => 'round', 'step' => 'Settle the check', 'field' => 'result_json', 'artifact' => 'fix'], 'commit' => ['job' => 'round', 'step' => 'Settle the check', 'field' => 'commit', 'artifact' => 'fix'],
            ]],
            'execution_copies' => [...$claimCopies, ...$vmResume['execution_copies'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-pi-token-[1-9][0-9]*$'],
                ['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-comment-write-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-token-[a-f0-9-]{36}$']],
        ], 'tasks.prep' => [
            'workflow' => 'operation-task-prep', 'projection' => TaskPrepProjection::class, 'github_token_issuer' => TaskGitHubTokens::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'prep', 'step' => 'Review has no findings', 'native' => true, 'captures' => [
                'provision' => ['job' => 'prep', 'step' => 'Claim a slot'],
                'draft' => ['job' => 'prep', 'step' => 'Draft the spec'],
                'review_1' => ['job' => 'prep', 'step' => 'Review the spec 1'], 'fix_1' => ['job' => 'prep', 'step' => 'Fix the findings 1'],
                'review_2' => ['job' => 'prep', 'step' => 'Review the spec 2'], 'fix_2' => ['job' => 'prep', 'step' => 'Fix the findings 2'],
                'review_3' => ['job' => 'prep', 'step' => 'Review the spec 3'],
                'publish' => ['job' => 'prep', 'step' => 'Open a draft PR'],
                'findings' => ['job' => 'prep', 'step' => 'Settle review 3', 'field' => 'findings', 'artifact' => 'state'],
            ]],
            'execution_copies' => [...$claimCopies,
                ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-token-[a-f0-9-]{36}$']],
        ], 'tasks.prep_fix' => [
            'workflow' => 'operation-task-prep-fix', 'projection' => TaskPrepFixProjection::class, 'github_token_issuer' => TaskGitHubTokens::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'corrections', 'step' => 'Review has no findings', 'native' => true, 'captures' => [
                'author' => ['job' => 'corrections', 'step' => 'Apply the corrections'],
                'review_1' => ['job' => 'corrections', 'step' => 'Review the spec 1'], 'fix_1' => ['job' => 'corrections', 'step' => 'Fix the findings 1'],
                'review_2' => ['job' => 'corrections', 'step' => 'Review the spec 2'],
                'head' => ['job' => 'corrections', 'step' => 'Settle review 2', 'field' => 'head', 'artifact' => 'state'],
                'findings' => ['job' => 'corrections', 'step' => 'Settle review 2', 'field' => 'findings', 'artifact' => 'state'],
            ]],
            'execution_copies' => [...$claimCopies, ...$vmResume['execution_copies'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-pi-token-[1-9][0-9]*$'],
                ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-token-[a-f0-9-]{36}$']],
        ], 'tasks.build' => [
            'workflow' => 'operation-task-build', 'projection' => TaskBuildProjection::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'build', 'step' => 'Run the first turn', 'native' => true, 'captures' => ['turn' => ['job' => 'build', 'step' => 'Run the first turn']]],
            'execution_copies' => [...$claimCopies, ...$vmResume['execution_copies'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-pi-token-[1-9][0-9]*$'],
                ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-sync-token-[a-f0-9-]{36}$']],
        ], 'tasks.sync' => [
            'workflow' => 'operation-task-sync', 'projection' => TaskSyncProjection::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm the sync', 'native' => true, 'captures' => ['refs' => ['job' => 'sync', 'step' => 'Read the refs', 'field' => 'stdout', 'artifact' => 'output']]],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-sync-token-[a-f0-9-]{36}$']],
        ]];
