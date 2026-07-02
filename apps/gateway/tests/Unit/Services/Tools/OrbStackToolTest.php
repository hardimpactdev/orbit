<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Tools\ToolCatalog;
use App\Tools\OrbStackTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('OrbStackTool', function (): void {
    it('has the correct slug, category, and macOS-only operating system support', function (): void {
        $tool = new OrbStackTool;

        expect($tool->slug())
            ->toBe('orbstack')
            ->and($tool->category())
            ->toBe('infrastructure')
            ->and($tool->supportedOperatingSystems())
            ->toBe(['macos']);
    });

    it('declares install, update, and safe-adopt capabilities without credentials or lifecycle ownership', function (): void {
        $tool = new OrbStackTool;

        expect($tool->capabilities())
            ->toContain('install')
            ->and($tool->capabilities())
            ->toContain('update')
            ->and($tool->capabilities())
            ->toContain('safe-adopt')
            ->and($tool->capabilities())
            ->not->toContain('credentials')->and($tool->capabilities())
            ->not->toContain('reconfigure')->and($tool->capabilities())
            ->not->toContain('remove')->and($tool->relatedProcess())->toBeNull();
    });

    it('installScript installs OrbStack through the official Homebrew cask channel', function (): void {
        $tool = new OrbStackTool;
        $script = (string) $tool->installScript();

        expect($script)
            ->toContain('# orbit install orbstack')
            ->and($script)
            ->toContain('set -e')
            ->and($script)
            ->toContain('brew install')
            ->and($script)
            ->toContain('orbstack')
            ->and($script)
            ->not->toContain('orb start')->and($script)
            ->not->toContain('orb stop')->and($script)
            ->not->toContain('orb restart');
    });

    it('updateScript upgrades OrbStack through the greedy Homebrew cask channel', function (): void {
        $tool = new OrbStackTool;
        $script = (string) $tool->updateScript();

        expect($script)
            ->toContain('brew upgrade --greedy orbstack')
            ->and($script)
            ->not->toContain('orb start')->and($script)
            ->not->toContain('orb stop')->and($script)
            ->not->toContain('orb restart');
    });

    it('probeMetadata detects orb, orbctl, the app bundle, and version without lifecycle commands', function (): void {
        $tool = new OrbStackTool;
        $metadata = $tool->probeMetadata();

        expect($metadata)
            ->toMatchArray([
                'binary' => '/usr/local/bin/orb',
            ])
            ->and($metadata['version_command'] ?? null)
            ->toBeString()
            ->toContain('orb version')
            ->and($metadata['probe'] ?? null)
            ->toBeString()
            ->toContain('/usr/local/bin/orbctl')
            ->and($metadata['probe'] ?? null)
            ->toContain('/Applications/OrbStack.app')
            ->and($metadata['update_command'] ?? null)
            ->toBe($tool->updateScript())
            ->and($metadata)
            ->not->toHaveKey('service')->and($metadata)
            ->not->toHaveKey('container')->and($metadata)
            ->not->toHaveKey('repair_commands');

        foreach ([
            $metadata['version_command'] ?? '',
            $metadata['probe'] ?? '',
            $metadata['update_command'] ?? '',
        ] as $command) {
            expect($command)
                ->not->toContain('orb start')
                ->not->toContain('orb stop')
                ->not->toContain('orb restart');
        }
    });

    it('is resolvable by slug from the tool catalog', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('orbstack'))->toBeInstanceOf(OrbStackTool::class);
    });

    it('supports active non-gateway macOS nodes and rejects Linux nodes', function (): void {
        $catalog = app(ToolCatalog::class);

        $macNode = Node::factory()->create([
            'name' => 'mac-orbstack',
            'status' => 'active',
            'platform' => 'macos_15-4',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $macNode->id,
            'role' => 'app-dev',
            'status' => 'active',
        ]);

        $linuxNode = Node::factory()->create([
            'name' => 'linux-orbstack',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $linuxNode->id,
            'role' => 'app-dev',
            'status' => 'active',
        ]);

        expect($catalog->supportsNode('orbstack', $macNode))
            ->toBeTrue()
            ->and($catalog->supportsNode('orbstack', $linuxNode))
            ->toBeFalse()
            ->and($catalog->logCommand('orbstack', 50))
            ->toBeNull();
    });
});
