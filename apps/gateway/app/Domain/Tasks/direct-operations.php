<?php

declare(strict_types=1);

use App\Domain\Tasks\DependencyMergeProjection;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskCopyTurnProjection;
use App\Domain\Tasks\TaskGitHubTokens;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\Tasks\TaskPublishProjection;
use App\Domain\Tasks\TaskPullRequestMergeProjection;
use App\Domain\Tasks\TaskPullRequestReadyProjection;
use App\Domain\Tasks\TaskSyncProjection;

return ['tasks.maintenance_finish' => ['workflow' => 'operation-task-cancel', 'projection' => TaskCancelProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the end', 'native' => true]], 'tasks.cancel' => ['workflow' => 'operation-task-cancel', 'projection' => TaskCancelProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the end', 'native' => true]], 'tasks.provision' => [
    // The request kind of `task:start`, which scopes the route's Idempotency-Key. No Operation of it starts: the start makes a task copy
    // through an `instance.create` and moves the key there, so the kind names the workflow that runs. The projection and the terminal
    // result read an old Operation, whose start recorded its own deleted workflow (docs/reference/old-paths.md#the-pi-task-loop).
    'workflow' => 'operation-instance-create', 'projection' => TaskProvisionProjection::class,
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'provision', 'step' => TaskProvisionProjection::EndCheck, 'native' => true],
], 'tasks.publish' => ['workflow' => 'operation-task-publish', 'projection' => TaskPublishProjection::class, 'github_token_issuer' => TaskGitHubTokens::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm publication', 'native' => true, 'captures' => ['pull_receipt' => ['job' => 'publish', 'step' => 'Read PR result', 'field' => 'stdout', 'artifact' => 'output'], 'base' => ['job' => 'publish', 'step' => 'Check the base', 'field' => 'stdout', 'artifact' => 'output']]], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-token-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-environment-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^nested-fleet-club-api-key$']]], 'tasks.check' => ['workflow' => 'operation-task-check', 'github_token_issuer' => TaskGitHubTokens::class, 'projection' => TaskCheckProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'Check the result', 'native' => true,
    'captures' => ['receipt' => ['job' => 'check', 'step' => 'Push the candidate', 'field' => 'finish'], 'instance_receipt' => ['job' => 'check', 'step' => 'Check in the Instance', 'field' => 'finish']]],
    'scheduled_result' => ['workflow' => 'task-check-ci', 'workflow_id' => '4a2d9dd5-9f83-41d4-b8df-8e46367ed230',
        'terminal_result' => ['job' => 'checks', 'step' => 'collect', 'data' => 'task-check-ci-data'], 'rows' => 'checks'], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-write-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-read-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-git-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-sync-token-[a-f0-9-]{36}$']]], 'tasks.copy_turn' => ['workflow' => 'operation-task-copy-turn', 'projection' => TaskCopyTurnProjection::class, 'workflow_inputs' => true,
            'terminal_result' => ['job' => 'turn', 'step' => 'outcome', 'native' => true, 'captures' => ['turn' => ['job' => 'turn', 'step' => 'result', 'field' => 'turn']]]], 'tasks.dependency_merge' => [
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
