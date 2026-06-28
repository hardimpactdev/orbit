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
    ]);

    it('does not advertise deferred solo command families', function (): void {
        $solo = $this->registry->require('solo');

        expect($solo->commands)
            ->toBe([])
            ->and($solo->permissions)
            ->toBe([])
            ->and($this->registry->extensionForCommand('solo:projects'))
            ->toBeNull();
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
