<?php

declare(strict_types=1);

use App\Domain\Processes\VpDevPreset;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ApiOperationTool;
use App\Http\Mcp\ToolManifest;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProcessDefinition;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

/** @param array<string, mixed> $spec
 * @return array{ProcessDefinition, Node}
 */
function definitionEditFixture(array $spec, array $environments = ['development']): array
{
    Http::preventStrayRequests();
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/srv/tool/default', 'status' => 'active']);
    $node->accessibleNodes()->attach($node->id);
    $definition = ProcessDefinition::query()->create([
        'project_id' => $project->id, 'name' => 'worker', 'environments' => $environments, 'spec' => $spec,
    ]);

    return [$definition, $node];
}

it('preserves the real command credential and private environment during an unrelated partial edit', function (): void {
    $original = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker', '--api-token=synthetic-command-secret'],
        'environment' => ['TOKEN' => 'synthetic-environment-secret'], 'keep_alive' => false];
    [$definition, $node] = definitionEditFixture($original);
    $url = "/api/v1/projects/{$definition->project_id}/process-definitions/worker";
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.spec.environment.TOKEN', '[REDACTED]');

    $this->putJson($url, ['name' => 'worker', 'environments' => ['development'], 'spec' => ['keep_alive' => true]])
        ->assertOk()->assertJsonPath('data.spec.environment.TOKEN', '[REDACTED]');

    expect($definition->refresh()->spec)->toBe([...$original, 'keep_alive' => true]);
});

it('clears an explicitly empty environment map and preserves other omitted fields', function (): void {
    [$definition, $node] = definitionEditFixture(['runtime' => 'systemd', 'command' => ['/usr/bin/worker'],
        'environment' => ['TOKEN' => 'synthetic-environment-secret']]);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => ['environment' => (object) []]],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe(['runtime' => 'systemd', 'command' => ['/usr/bin/worker'], 'environment' => []]);
});

it('replaces runtime-specific fields when converting a Docker definition to systemd', function (): void {
    [$definition, $node] = definitionEditFixture(['runtime' => 'docker', 'image' => 'worker:1', 'command' => ['worker'],
        'ports' => ['8080:80'], 'volumes' => [['source' => 'cache', 'target' => '/cache']],
        'environment' => ['TOKEN' => 'synthetic-environment-secret']], ['production']);

    $replacement = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker']];
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['production'], 'spec' => $replacement],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe($replacement);
});

it('publishes a schema-valid captured preset edit and refuses invalid commands through MCP', function (bool $versioned): void {
    $spec = ['runtime' => 'systemd', 'command' => VpDevPreset::definitionCommand(),
        'preset' => 'vp-dev', 'keep_alive' => false, ...($versioned ? ['preset_version' => 1] : [])];
    [$definition, $node] = definitionEditFixture($spec);
    $manifest = collect(ToolManifest::default()->definitions())
        ->firstWhere('name', 'project-process-definition-update');
    $schema = $manifest->inputSchema['properties']['spec'];
    expect($schema['required'] ?? [])->not->toContain('runtime', 'command');
    expect($schema['properties']['preset'])->toMatchArray(['type' => 'string', 'enum' => ['vp-dev']]);
    expect($schema['properties']['preset_version'])->toMatchArray(['type' => 'integer', 'enum' => [1]]);
    $tool = new ApiOperationTool($manifest, app(ApiDispatcher::class));
    $caller = Request::create('/mcp', 'POST', server: ['REMOTE_ADDR' => $node->wireguard_ip]);
    $arguments = ['project' => $definition->project_id, 'process_definition' => $definition->name,
        'name' => $definition->name, 'environments' => ['development'],
        'spec' => ['keep_alive' => true, 'preset' => 'vp-dev', ...($versioned ? ['preset_version' => 1] : [])]];

    $response = $tool->handle(new Laravel\Mcp\Request($arguments), $caller);

    expect(json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR)['data']['spec']['keep_alive'])->toBeTrue();
    expect($definition->refresh()->spec)->toBe([...$spec, 'keep_alive' => true]);
    $arguments['spec'] = ['command' => ['vp', 'custom']];
    $response = $tool->handle(new Laravel\Mcp\Request($arguments), $caller);
    $error = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    expect($error['status'])->toBe(422);
    expect($error['error']['details']['spec.preset'])->toContain(
        'Only an existing captured vp-dev definition with its canonical command and preset metadata can be edited.',
    );
    expect($definition->refresh()->spec)->toBe([...$spec, 'keep_alive' => true]);
})->with(['unversioned capture' => false, 'versioned capture' => true]);
