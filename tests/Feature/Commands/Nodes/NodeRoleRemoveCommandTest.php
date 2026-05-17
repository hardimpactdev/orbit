<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleCommandTestHelpers.php';

describe('node role:remove', function (): void {
    it('requires force in non-interactive json mode even when there are no dependents', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'app-development', settings: ['tld' => 'test']);

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force')
            ->and($node->roleAssignments()->where('role', 'app-development')->exists())->toBeTrue();
    });

    it('blocks removal when dependents exist after interactive confirmation', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'app-development', settings: ['tld' => 'test']);
        App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);

        /** @phpstan-ignore-next-line Pest resolves artisan() on the bound Laravel test case at runtime. */
        $this->artisan('node role:remove', [
            'node' => 'client-1',
            'role' => 'app-development',
        ])
            ->expectsConfirmation("Remove role 'app-development' from 'client-1'?", 'yes')
            ->expectsOutputToContain("Role 'app-development' cannot be removed while dependents exist.")
            ->assertExitCode(1);

        expect($node->roleAssignments()->where('role', 'app-development')->exists())->toBeTrue();
    });

    it('requires force when purge-data is requested', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'db-1',
        ]);

        assignNodeRole($node, 'database');

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'db-1',
            'role' => 'database',
            '--purge-data' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('purge-data');
    });

    it('rejects gateway role removal before side effects', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'gateway-2',
            'role' => 'gateway',
            'environment' => null,
        ]);

        assignNodeRole($node, 'gateway');

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'gateway-2',
            'role' => 'gateway',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toMatchArray([
                'field' => 'role',
                'role' => 'gateway',
            ])
            ->and($node->roleAssignments()->where('role', 'gateway')->exists())->toBeTrue();
    });

    it('force removes Orbit-owned role dependents without reporting data purge', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'db-1',
        ]);

        assignNodeRole($node, 'database');

        NodeTool::query()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
            'installed_version' => null,
            'settings' => [],
            'status' => 'running',
        ]);

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'db-1',
            'role' => 'database',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['purged_data'])->toBeFalse()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeFalse();
    });

    it('force with purge-data removes role dependents', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'db-1',
        ]);

        assignNodeRole($node, 'database');

        NodeTool::query()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
            'installed_version' => null,
            'settings' => [],
            'status' => 'running',
        ]);

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'db-1',
            'role' => 'database',
            '--force' => true,
            '--purge-data' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['purged_data'])->toBeTrue()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeFalse();
    });

    it('returns an error when local role removal cleanup fails', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);
        $assignment = assignNodeRole($node, 'app-development', settings: ['tld' => 'test']);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct()
            {
                parent::__construct(
                    app(GatewayRoleBaseline::class),
                    app(AppDevelopmentRoleBaseline::class),
                    app(AppProductionRoleBaseline::class),
                    app(DatabaseRoleBaseline::class),
                );
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('Cleanup failed.');
            }
        });

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_role.remove_failed')
            ->and($payload['error']['meta']['last_error'])->toBe('Cleanup failed.')
            ->and($assignment->fresh()->status)->toBe('error')
            ->and($assignment->fresh()->last_error)->toBe('Cleanup failed.');
    });
});
