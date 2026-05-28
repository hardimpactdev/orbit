<?php

declare(strict_types=1);

use App\Commands\BootstrapGatewayCommand;
use App\Commands\GatewayCommand;
use App\Commands\Internal\InternalExecutorCommand;
use App\Commands\LocalOnlyCommand;

/**
 * ORBIT-CLI-ARCH-01 (D18) — keep CLI command classes adapter-thin.
 *
 * Public CLI commands must parse input, resolve local config, call a gateway transport or
 * action/service, and render output. They must not embed workflow orchestration, persistence
 * policy, retry behaviour, queueing, or host mutation logic in `handle()`. This is enforced
 * by structural inheritance: every public command class under `App\Commands\*` must extend
 * exactly one of the documented base classes that already encodes the right boundary.
 *
 *   - `GatewayCommand`: public gateway-backed commands (must go through GatewayApiClient).
 *   - `LocalOnlyCommand`: caller-local config/env mutations.
 *   - `BootstrapGatewayCommand`: bootstrap surface that may run before a gateway exists.
 *   - `InternalExecutorCommand`: hidden `internal:*` commands that carry an operation token.
 *
 * Commands that need new behaviour are expected to compose with services/actions rather
 * than inheriting from a less constrained Laravel Zero base.
 */
final class ThinCliCommandsTest
{
    /**
     * @return list<string>
     */
    public static function publicAndInternalCommandClasses(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3).'/config/commands.php';

        $add = $config['add'] ?? [];
        $hidden = $config['hidden'] ?? [];

        $classes = [];

        foreach (array_merge((array) $add, (array) $hidden) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! str_starts_with($class, 'App\\Commands\\')) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}

describe('ORBIT-CLI-ARCH-01 — thin CLI command guardrail', function (): void {
    it('registers commands explicitly (no path discovery) so the guardrail can enumerate them', function (): void {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3).'/config/commands.php';

        expect($config['paths'] ?? null)->toBe([]);
    });

    it('discovers at least the deploy, firewall, and internal:executor command classes via the explicit registration', function (): void {
        $classes = ThinCliCommandsTest::publicAndInternalCommandClasses();

        expect($classes)->toContain('App\\Commands\\Deploy\\DeployHistoryCommand')
            ->and($classes)->toContain('App\\Commands\\Deploy\\DeployStepListCommand')
            ->and($classes)->toContain('App\\Commands\\Firewall\\FirewallListCommand')
            ->and($classes)->toContain('App\\Commands\\Internal\\VerifyExecutorCommand');
    });

    it('keeps every registered App\\Commands\\* class extending exactly one of the documented adapter base classes', function (string $class): void {
        $allowedBases = [
            GatewayCommand::class,
            LocalOnlyCommand::class,
            BootstrapGatewayCommand::class,
            InternalExecutorCommand::class,
        ];

        $matched = array_filter(
            $allowedBases,
            static fn (string $base): bool => is_subclass_of($class, $base),
        );

        expect($matched)
            ->not->toBeEmpty()
            ->and(count($matched))->toBe(1, "{$class} must extend exactly one of: GatewayCommand, LocalOnlyCommand, BootstrapGatewayCommand, InternalExecutorCommand.");
    })->with(ThinCliCommandsTest::publicAndInternalCommandClasses());

    it('keeps the canonical adapter base classes themselves out of the workflow-orchestration business', function (): void {
        // The bases must NOT carry their own persistence/orchestration helpers. Adding methods
        // that mutate gateway/node state directly here would let any subclass bypass D18.
        $forbiddenMethodFragments = ['save', 'persist', 'mutate', 'install', 'provision'];

        $bases = [GatewayCommand::class, LocalOnlyCommand::class, BootstrapGatewayCommand::class, InternalExecutorCommand::class];

        foreach ($bases as $base) {
            $reflection = new ReflectionClass($base);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                if ($method->getDeclaringClass()->getName() !== $base) {
                    continue;
                }

                $name = strtolower($method->getName());

                foreach ($forbiddenMethodFragments as $fragment) {
                    expect(str_contains($name, $fragment))
                        ->toBeFalse("{$base}::{$method->getName()} contains forbidden fragment '{$fragment}' — workflow orchestration must live in a service, not a command base.");
                }
            }
        }
    });
});
