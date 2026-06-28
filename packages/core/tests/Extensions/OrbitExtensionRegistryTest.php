<?php

declare(strict_types=1);

use Orbit\Core\Extensions\OrbitExtensionRegistry;

describe(OrbitExtensionRegistry::class, function (): void {
    beforeEach(function (): void {
        $this->registry = new OrbitExtensionRegistry;
    });

    it('exposes the built-in extension slugs in stable order', function (): void {
        expect($this->registry->slugs())->toBe([
            'cloudflare',
            'codex',
            'solo',
        ]);
    });

    it('maps extension commands to their owning extension definition', function (string $command, string $slug): void {
        expect($this->registry->extensionForCommand($command)?->slug)->toBe($slug);
    })->with([
        'cloudflare zone list' => ['cf-zone:list', 'cloudflare'],
        'codex app' => ['codex:app', 'codex'],
        'solo project list' => ['solo:project:list', 'solo'],
        'solo process list' => ['solo:process:list', 'solo'],
        'solo scratchpad list' => ['solo:scratchpad:list', 'solo'],
        'solo todo list' => ['solo:todo:list', 'solo'],
    ]);

    it('advertises the planned solo command catalog with flat orbit command names', function (): void {
        $solo = $this->registry->require('solo');

        expect($solo->commands)
            ->toContain(
                'solo:project:list',
                'solo:process:list',
                'solo:scratchpad:list',
                'solo:todo:list',
            )
            ->each->toStartWith('solo:')->and($solo->commands)
            ->not->toContain(
                'solo:project list',
                'solo:process list',
                'solo:scratchpad list',
                'solo:todo list',
            );
    });

    it('maps every solo command to the solo extension', function (): void {
        $solo = $this->registry->require('solo');

        foreach ($solo->commands as $command) {
            expect($this->registry->extensionForCommand($command)?->slug)->toBe('solo');
        }
    });

    it('advertises granular solo permission families', function (): void {
        $solo = $this->registry->require('solo');

        expect($solo->permissions)
            ->toContain(
                'solo:*',
                'solo:status',
                'solo:help',
                'solo:tools',
                'solo:feedback',
                'solo:project:*',
                'solo:project:list',
                'solo:project:show',
                'solo:project:create',
                'solo:project:update',
                'solo:project:delete',
                'solo:process:*',
                'solo:process:read',
                'solo:process:spawn',
                'solo:process:input',
                'solo:process:lifecycle',
                'solo:process:delete',
                'solo:agent:*',
                'solo:agent-tool:list',
                'solo:agent:spawn',
                'solo:agent:setup',
                'solo:scratchpad:*',
                'solo:scratchpad:read',
                'solo:scratchpad:write',
                'solo:scratchpad:delete',
                'solo:scratchpad:transfer',
                'solo:todo:*',
                'solo:todo:read',
                'solo:todo:write',
                'solo:todo:delete',
                'solo:todo:lock',
                'solo:todo:comment',
                'solo:lock:*',
                'solo:timer:*',
                'solo:readiness:*',
            );
    });

    it('returns null for commands that are not owned by an extension', function (): void {
        expect($this->registry->extensionForCommand('node:list'))->toBeNull();
    });

    it('returns null for unknown extension slugs', function (): void {
        expect($this->registry->get('missing'))->toBeNull();
    });

    it('throws for unknown extension slugs via require', function (): void {
        $this->registry->require('missing');
    })->throws(InvalidArgumentException::class, 'Unknown Orbit extension [missing].');

    it('does not duplicate command names across extension definitions', function (): void {
        $commands = [];

        foreach ($this->registry->all() as $definition) {
            foreach ($definition->commands as $command) {
                expect($commands)->not->toHaveKey($command);

                $commands[$command] = $definition->slug;
            }
        }
    });
});
