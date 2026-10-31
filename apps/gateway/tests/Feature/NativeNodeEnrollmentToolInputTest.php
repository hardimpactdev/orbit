<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\NodeEnrollmentToolInput;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Tool;

function enrollmentToolCandidate(): NodeEnrollmentCandidate
{
    return new NodeEnrollmentCandidate(73, 'new-node', 'orbit', 'linux', null, '10.44.0.73', '203.0.113.73', 22, null, 'test', null, null, null);
}

it('selects application tools and exact pins without importing the full base image', function (): void {
    $input = app(NodeEnrollmentToolInput::class)->capture(enrollmentToolCandidate(), [RoleName::AppDev]);
    $recipe = new RecipeLoader()->load();
    expect($input['node_id'])->toBe(73)
        ->and(array_keys($input['javascript']['tools'] ?? []))->toBe(['bun', 'node', 'pnpm', 'vp', 'vp-cli'])
        ->and(($input['javascript']['tools']['node']['version'] ?? null))->toBe($recipe->tools['node']->version)
        ->and(($input['javascript']['tools']['node']['digest'] ?? null))->toBe($recipe->tools['node']->artifacts['amd64']->digest)
        ->and(($input['composer']['version'] ?? null))->toBe($recipe->tools['composer']->version)
        ->and(array_column($input['apt']['packages'], 'name'))->not->toContain('swamp', 'rust', 'cargo', 'pi', 'incus', 'chrome-headless-shell')
        ->and(array_column($input['apt']['packages'], 'name'))->toContain('php8.5-cli', 'php8.4-cli')
        ->and(Tool::query()->count())->toBe(0);
});

it('keeps javascript and Composer absent for infrastructure and roleless Nodes', function (?RoleName $role): void {
    $input = app(NodeEnrollmentToolInput::class)->capture(enrollmentToolCandidate(), $role === null ? [] : [$role]);
    expect($input['javascript'])->toBeNull()->and($input['composer'])->toBeNull()
        ->and(array_column($input['apt']['packages'], 'name'))->not->toContain('swamp', 'rust', 'cargo', 'pi');
})->with([null, RoleName::Gateway, RoleName::Vpn, RoleName::Router, RoleName::Ingress, RoleName::Database, RoleName::Metrics, RoleName::Analytics, RoleName::CiRunner]);

it('prepares Composer for WebSocket without installing application Javascript', function (): void {
    $input = app(NodeEnrollmentToolInput::class)->capture(enrollmentToolCandidate(), [RoleName::WebSocket]);
    expect($input['javascript'])->toBeNull()->and(($input['composer']['user'] ?? null))->toBe('orbit')
        ->and(array_column($input['apt']['packages'], 'name'))->toContain('composer', 'git', 'php8.5-cli');
});

it('unions role pins once and retains production browser requirements only for app-prod', function (): void {
    $input = app(NodeEnrollmentToolInput::class)->capture(enrollmentToolCandidate(), [RoleName::AppDev, RoleName::AppProd, RoleName::Metrics]);
    $names = array_column($input['apt']['packages'], 'name');
    expect($names)->toBe(array_values(array_unique($names)))
        ->and($names)->toContain('apparmor', 'libnss3', 'containerd', 'docker.io')
        ->and(($input['browser']['version'] ?? null))->toBe((new RecipeLoader)->load()->tools['chrome-headless-shell']->version)
        ->and(($input['caddy']['release'] ?? null))->toBe('2.11.7');
});
