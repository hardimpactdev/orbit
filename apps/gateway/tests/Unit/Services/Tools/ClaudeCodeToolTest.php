<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Tools\ToolCatalog;
use App\Tools\ClaudeCodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('ClaudeCodeTool', function (): void {
    it('is registered as an install-capable runtime tool, not an agent tool', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('claude-code'))
            ->toBeInstanceOf(ClaudeCodeTool::class)
            ->and($catalog->category('claude-code'))
            ->toBe('runtime')
            ->and($catalog->requiredNodeRole('claude-code'))
            ->toBeNull()
            ->and($catalog->supportedOperatingSystems('claude-code'))
            ->toBe(['linux', 'macos'])
            ->and($catalog->hasCapability('claude-code', 'install'))
            ->toBeTrue()
            ->and($catalog->category('claude-code'))
            ->not->toBe('agent');
    });

    it('supports install on authorized active non-gateway linux nodes without a required role', function (): void {
        $catalog = app(ToolCatalog::class);
        $node = Node::factory()->create([
            'name' => 'app-claude-support',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
        ]);

        expect($catalog->supportsNode('claude-code', $node))->toBeTrue();
    });

    it('installs Claude Code for each target user through the native installer and verifies the binary', function (): void {
        $tool = new ClaudeCodeTool;
        $script = $tool->installScript([
            'default_user' => 'orbit',
            'install_users' => ['agent'],
            'version' => 'stable',
        ]);

        expect($script)
            ->toContain('https://claude.ai/install.sh')
            ->toContain("sudo -u 'orbit' -H bash -lc")
            ->toContain("sudo -u 'agent' -H bash -lc")
            ->toContain('bash -s "$1"')
            ->toContain("'bash' 'stable'")
            ->toContain('claude --version')
            ->not->toContain('/usr/local/bin/claude')
            ->not->toContain('proxy')
            ->not->toContain('hermes');
    });

    it('returns config-free probe metadata for the default managed orbit user', function (): void {
        $tool = new ClaudeCodeTool;

        expect($tool->probeMetadata())
            ->toBe($tool->probeMetadata())
            ->toMatchArray([
                'binary' => '/home/orbit/.local/bin/claude',
                'version_command' => "sudo -u 'orbit' -H bash -lc '/home/orbit/.local/bin/claude --version'",
            ])
            ->not->toHaveKey('service');
    });
});
