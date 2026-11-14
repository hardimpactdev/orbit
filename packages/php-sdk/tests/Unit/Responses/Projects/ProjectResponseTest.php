<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Projects\ProjectResponse;

describe(ProjectResponse::class, function (): void {
    it('maps every public project field from gateway data', function (): void {
        $response = ProjectResponse::fromGatewayData([
            'id' => 3,
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'source_access' => 'gh_cli',
            'default_branch' => 'main',
            'root' => 'public',
            'task_check' => 'composer check',
            'task_workspace_routed' => false,
        ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect($response->toArray())->toBe([
            'id' => 3,
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'source_access' => 'gh_cli',
            'default_branch' => 'main',
            'root' => 'public',
            'task_check' => 'composer check',
            'task_workspace_routed' => false,
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });

    it('maps the copy turn agent defaults and labels each role', function (): void {
        $response = ProjectResponse::fromGatewayData(['id' => 3, 'task_implementer_provider' => 'claude', 'task_implementer_model' => 'claude-sonnet-5-5',
            'task_implementer_effort' => null, 'task_reviewer_provider' => null, 'task_reviewer_model' => null, 'task_reviewer_effort' => null], 'request-id');

        expect($response->taskAgentLabel('implementer'))->toBe('claude / claude-sonnet-5-5 / -')
            ->and($response->taskAgentLabel('reviewer'))->toBeNull()
            ->and($response->toArray())->toMatchArray(['task_implementer_model' => 'claude-sonnet-5-5', 'task_reviewer_effort' => null])
            ->and(ProjectResponse::fromGatewayData(['id' => 3], 'request-id')->toArray())->not->toHaveKey('task_implementer_model');
    });

    it('normalizes invalid optional source defaults to null', function (): void {
        $response = ProjectResponse::fromGatewayData(['default_branch' => ['invalid'], 'root' => ['invalid']], 'request-id');

        expect($response->defaultBranch)
            ->toBeNull()
            ->and($response->root)
            ->toBeNull();
    });
});
