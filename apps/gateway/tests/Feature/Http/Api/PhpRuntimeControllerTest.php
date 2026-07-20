<?php

declare(strict_types=1);

use App\Contracts\PhpRuntimeArtifactConverger;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PHP_API_CALLER_WG_IP = '10.6.0.97';

/** @mago-expect lint:file-name */
final readonly class PhpApiNoopRuntimeArtifactConverger implements PhpRuntimeArtifactConverger
{
    public function forApp(Project $app): array
    {
        return [];
    }

    public function forWorkspace(Workspace $workspace, Node $node): array
    {
        return [];
    }
}

function createPhpApiCaller(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => PHP_API_CALLER_WG_IP,
        'wireguard_address' => PHP_API_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantPhpApiAccess(Node $caller, Node $appNode, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function place_php_api_app(Project $app, Node $node, string $name = 'development'): AppInstance
{
    return AppInstance::factory()->for($app)->create([
        'name' => $name,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);
}

/** @mago-expect lint:halstead */
describe('PHP runtime API controllers', function (): void {
    beforeEach(function (): void {
        app()->instance(PhpRuntimeArtifactConverger::class, new PhpApiNoopRuntimeArtifactConverger);
    });

    it('rejects app-prod callers inspecting workspace PHP despite a legacy read grant', function (): void {
        $caller = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-caller',
                'host' => PHP_API_CALLER_WG_IP,
                'wireguard_address' => PHP_API_CALLER_WG_IP,
            ]);
        $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
        grantPhpApiAccess($caller, $node, ['php:read']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $instance = place_php_api_app($app, $node);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
        ]);

        $response = $this->call(
            'GET',
            '/api/php/runtime?instance=docs.development&workspace=feature-docs&live=1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
            ->assertJsonPath('error.meta.node', 'app-prod-caller')
            ->assertJsonPath('error.meta.role', 'app-prod');
    });

    it('rejects app-prod callers mutating workspace PHP despite a legacy write grant', function (): void {
        $caller = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-caller',
                'host' => PHP_API_CALLER_WG_IP,
                'wireguard_address' => PHP_API_CALLER_WG_IP,
            ]);
        $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
        grantPhpApiAccess($caller, $node, ['php:write']);
        $app = Project::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'php_version' => '8.4',
        ]);
        $instance = place_php_api_app($app, $node);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'php_version' => '8.4',
        ]);

        $response = $this->call(
            'POST',
            '/api/php/use',
            [
                'version' => '8.5',
                'instance' => 'docs.development',
                'workspace' => 'feature-docs',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
            ->assertJsonPath('error.meta.node', 'app-prod-caller')
            ->assertJsonPath('error.meta.role', 'app-prod');

        expect($workspace->refresh()->php_version)->toBe('8.4');
    });

    it('rejects instance-target PHP writes from app-prod callers before inherited workspace fanout', function (): void {
        $caller = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-caller',
                'host' => PHP_API_CALLER_WG_IP,
                'wireguard_address' => PHP_API_CALLER_WG_IP,
            ]);
        $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
        grantPhpApiAccess($caller, $node, ['php:write']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'php',
            'config' => [
                'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm'],
                'versions' => ['8.5'],
                'cli_version' => '8.5',
            ],
        ]);
        $app = Project::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'php_version' => '8.4',
        ]);
        $instance = place_php_api_app($app, $node);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'php_version' => null,
        ]);

        $response = $this->call(
            'POST',
            '/api/php/use',
            [
                'version' => '8.5',
                'instance' => 'docs.development',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
            ->assertJsonPath('error.meta.node', 'app-prod-caller')
            ->assertJsonPath('error.meta.role', 'app-prod');

        expect($app->refresh()->php_version)->toBe('8.4');
    });

    it('rejects instance-target PHP writes for app-prod targets before cross-instance workspace fanout', function (): void {
        $caller = createPhpApiCaller();
        $productionNode = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
        $developmentNode = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
        grantPhpApiAccess($caller, $productionNode, ['php:write']);
        NodeTool::factory()->create([
            'node_id' => $productionNode->id,
            'name' => 'php',
            'config' => [
                'images' => ['ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm'],
                'versions' => ['8.5'],
                'cli_version' => '8.5',
            ],
        ]);
        $app = Project::factory()->create([
            'name' => 'docs',
            'node_id' => $productionNode->id,
            'php_version' => '8.4',
        ]);
        place_php_api_app($app, $productionNode, 'production');
        $development = place_php_api_app($app, $developmentNode);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $development->id,
            'php_version' => null,
        ]);

        $response = $this->call(
            'POST',
            '/api/php/use',
            [
                'version' => '8.5',
                'instance' => 'docs.production',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
            ->assertJsonPath('error.meta.node', 'app-prod-1')
            ->assertJsonPath('error.meta.role', 'app-prod');

        expect($app->refresh()->php_version)->toBe('8.4');
    });

    it('rejects PHP reads for workspaces owned by app-prod targets', function (): void {
        $caller = createPhpApiCaller();
        $node = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
        grantPhpApiAccess($caller, $node, ['php:read']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'php',
            'config' => ['versions' => ['8.5'], 'cli_version' => '8.5'],
        ]);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $instance = place_php_api_app($app, $node);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
        ]);

        $response = $this->call(
            'GET',
            '/api/php/runtime?instance=docs.development&workspace=feature-docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
            ->assertJsonPath('error.meta.node', 'app-prod-1')
            ->assertJsonPath('error.meta.role', 'app-prod');
    });

    it('returns a PHP runtime view for an authorized caller', function (): void {
        $caller = createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        grantPhpApiAccess($caller, $node, ['php:read']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'php',
            'config' => ['versions' => ['8.5'], 'cli_version' => '8.5'],
        ]);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => null]);

        $response = $this->call(
            'GET',
            '/api/php/runtime?instance=docs&workspace=feature-docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.php.node', 'app-1')
            ->assertJsonPath('success.data.php.workspace.inherits', true);
    });

    it('writes app PHP runtime intent for an authorized caller', function (): void {
        $caller = createPhpApiCaller();
        $legacyNode = Node::factory()->appDev()->create(['name' => 'legacy-app-1']);
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        grantPhpApiAccess($caller, $node, ['php:write']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'php',
            'config' => [
                'versions' => ['8.5', '8.4'],
                'images' => [
                    'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm',
                    'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm',
                ],
                'cli_version' => '8.5',
            ],
        ]);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $legacyNode->id, 'php_version' => '8.4']);
        place_php_api_app($app, $node);

        $response = $this->call(
            'POST',
            '/api/php/use',
            [
                'version' => '8.5',
                'instance' => 'docs',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.result.target', 'instance');

        expect($app->refresh()->php_version)->toBe('8.5');
    });

    it('returns authorization failure for hidden nodes', function (): void {
        createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'php',
            'config' => ['versions' => ['8.5'], 'cli_version' => '8.5'],
        ]);

        $response = $this->call(
            'GET',
            '/api/php/runtime?node=app-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('authorizes app-selected targets against their owning node', function (): void {
        createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'php',
            'config' => ['versions' => ['8.5'], 'cli_version' => '8.5'],
        ]);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        place_php_api_app($app, $node);

        $response = $this->call(
            'GET',
            '/api/php/runtime?instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('denies PHP reads when the grant has only the write permission', function (): void {
        $caller = createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        grantPhpApiAccess($caller, $node, ['php:write']);

        $response = $this->call(
            'GET',
            '/api/php/runtime?node=app-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'php:read')
            ->assertJsonPath('error.meta.serving_node', 'app-1');
    });

    it('denies PHP writes when the grant has only the read permission', function (): void {
        $caller = createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        grantPhpApiAccess($caller, $node, ['php:read']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        place_php_api_app($app, $node);

        $response = $this->call(
            'POST',
            '/api/php/use',
            ['version' => '8.5', 'instance' => 'docs'],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'php:write')
            ->assertJsonPath('error.meta.serving_node', 'app-1');
    });

    it('denies PHP access when the target cannot be resolved', function (): void {
        createPhpApiCaller();

        $response = $this->call(
            'GET',
            '/api/php/runtime?instance=missing',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'serving_node_unresolved')
            ->assertJsonPath('error.meta.missing_permission', 'php:read');
    });

    it('denies PHP access for an unidentified caller', function (): void {
        $response = $this->call(
            'GET',
            '/api/php/runtime?node=missing',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '10.6.0.199'],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });

    it('keeps gateway PHP authority implicit', function (): void {
        $gateway = createTestGatewayNode([
            'name' => 'gateway-php',
            'host' => PHP_API_CALLER_WG_IP,
            'wireguard_address' => PHP_API_CALLER_WG_IP,
        ]);
        NodeTool::factory()->create([
            'node_id' => $gateway->id,
            'name' => 'php',
            'config' => ['versions' => ['8.5'], 'cli_version' => '8.5'],
        ]);

        $response = $this->call(
            'GET',
            '/api/php/runtime?node=gateway-php',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.php.node', 'gateway-php');
    });

    it('authorizes and reads instance targets through concrete instance placement', function (): void {
        $caller = createPhpApiCaller();
        $legacyNode = Node::factory()->appDev()->create(['name' => 'legacy-app-node']);
        $servingNode = Node::factory()->appDev()->create(['name' => 'instance-node']);
        grantPhpApiAccess($caller, $servingNode, ['php:read']);
        NodeTool::factory()->create([
            'node_id' => $servingNode->id,
            'name' => 'php',
            'config' => ['versions' => ['8.5'], 'cli_version' => '8.5'],
        ]);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $legacyNode->id]);
        place_php_api_app($app, $servingNode, name: 'production');

        $response = $this->call(
            'GET',
            '/api/php/runtime?instance=docs.production',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.php.node', 'instance-node');
    });

    it('authorizes and reads workspace targets through workspace placement', function (): void {
        $caller = createPhpApiCaller();
        $legacyNode = Node::factory()->appDev()->create(['name' => 'legacy-app-node']);
        $servingNode = Node::factory()->appDev()->create(['name' => 'workspace-node']);
        grantPhpApiAccess($caller, $servingNode, ['php:read']);
        NodeTool::factory()->create([
            'node_id' => $servingNode->id,
            'name' => 'php',
            'config' => ['versions' => ['8.5'], 'cli_version' => '8.5'],
        ]);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $legacyNode->id]);
        $instance = place_php_api_app($app, $servingNode);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
        ]);

        $response = $this->call(
            'GET',
            '/api/php/runtime?workspace=feature-docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.php.node', 'workspace-node');
    });
});
