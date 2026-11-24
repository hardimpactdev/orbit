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
    it('maps task_pr_mode, null included, and leaves it out when the Gateway sends none', function (): void {
        expect(ProjectResponse::fromGatewayData(['id' => 3, 'task_pr_mode' => 'pr_when_done'], 'request-id')->taskPrMode)->toBe('pr_when_done')
            ->and(ProjectResponse::fromGatewayData(['id' => 3, 'task_pr_mode' => null], 'request-id')->toArray())->toMatchArray(['task_pr_mode' => null])
            ->and(ProjectResponse::fromGatewayData(['id' => 3], 'request-id')->toArray())->not->toHaveKey('task_pr_mode');
    });
    it('maps the Project list summary and leaves it out when the Gateway sends none', function (): void {
        $summary = ['repository' => 'github.com/acme/site', 'node_name' => 'mini', 'icon' => null, 'color' => null, 'instance_count' => 3];

        expect(ProjectResponse::fromGatewayData(['id' => 3, ...$summary], 'request-id')->summary)->toBe($summary)
            ->and(ProjectResponse::fromGatewayData(['id' => 3, ...$summary], 'request-id')->toArray())->toMatchArray($summary)
            ->and(ProjectResponse::fromGatewayData(['id' => 3, 'node_name' => 'mini'], 'request-id')->toArray())->not->toHaveKeys(['node_name', 'instance_count']);
    });
    it('maps default_auto_deploy and leaves it out when the Gateway sends none', function (): void {
        expect(ProjectResponse::fromGatewayData(['id' => 3, 'default_auto_deploy' => false], 'request-id')->toArray())->toMatchArray(['default_auto_deploy' => false])
            ->and(ProjectResponse::fromGatewayData(['id' => 3], 'request-id')->defaultAutoDeploy)->toBeNull()
            ->and(ProjectResponse::fromGatewayData(['id' => 3], 'request-id')->toArray())->not->toHaveKey('default_auto_deploy');
    });
    it('maps container_server, and leaves it out when the Gateway sends none', function (): void {
        expect(ProjectResponse::fromGatewayData(['id' => 3, 'container_server' => 'conn'], 'request-id')->containerServer)->toBe('conn')
            ->and(ProjectResponse::fromGatewayData(['id' => 3, 'container_server' => 't3'], 'request-id')->toArray())->toMatchArray(['container_server' => 't3'])
            ->and(ProjectResponse::fromGatewayData(['id' => 3], 'request-id')->toArray())->not->toHaveKey('container_server');
    });
    it('maps agent_instructions and its label, and leaves it out when the Gateway sends none', function (): void {
        $own = ProjectResponse::fromGatewayData(['id' => 3, 'agent_instructions' => ['source' => 'project', 'agents_md_version' => 2, 'skills' => ['a']]], 'request-id');
        expect($own->agentInstructions)->toBe(['source' => 'project', 'agents_md_version' => 2, 'skills' => ['a']])
            ->and($own->agentInstructionsLabel())->toBe('project: agent/AGENTS.md v2, skills: a')
            ->and($own->toArray())->toMatchArray(['agent_instructions' => ['source' => 'project', 'agents_md_version' => 2, 'skills' => ['a']]])
            ->and(ProjectResponse::fromGatewayData(['id' => 3, 'agent_instructions' => ['source' => 'type_note', 'agents_md_version' => null, 'skills' => []]], 'request-id')
                ->agentInstructionsLabel())->toBe('type note')
            ->and(ProjectResponse::fromGatewayData(['id' => 3], 'request-id')->agentInstructionsLabel())->toBeNull()
            ->and(ProjectResponse::fromGatewayData(['id' => 3], 'request-id')->toArray())->not->toHaveKey('agent_instructions');
    });
});
