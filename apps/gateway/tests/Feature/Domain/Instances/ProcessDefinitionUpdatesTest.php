<?php

declare(strict_types=1);

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Domain\Processes\ProcessTargetResolver;
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

it('preserves every stored credential byte when replaying redacted command elements and environment values', function (): void {
    $original = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker', '--api-token=synthetic +/=secret', 'literal-secret'],
        'environment' => ['TOKEN' => 'synthetic-environment-secret'], 'working_directory' => '/srv/tool/custom'];
    [$definition, $node] = definitionEditFixture($original);
    $replayed = [...$original, 'command' => ['/usr/bin/worker', '--api-token=[REDACTED]', '[REDACTED]'],
        'environment' => ['TOKEN' => '[REDACTED]']];

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => $replayed],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe($original);
});

it('refuses a placeholder with no stored value at its command position or environment key', function (array $change, string $field): void {
    $original = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker']];
    [$definition, $node] = definitionEditFixture($original);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => $change],
    );

    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($response->json('error.details')[$field])->toBe([
        'A redacted value must match an existing stored value at the same position or key.',
    ]);
    expect($definition->refresh()->spec)->toBe($original);
})->with([
    'longer command' => [['command' => ['/usr/bin/worker', '--api-token=[REDACTED]']], 'spec.command.1'],
    'unknown environment key' => [['environment' => ['UNKNOWN' => '[REDACTED]']], 'spec.environment.UNKNOWN'],
]);

it('removes a scalar override with null and restores runtime defaults', function (string $runtime, string $field, mixed $value): void {
    $base = ['runtime' => $runtime, 'command' => ['/usr/bin/worker'], ...($runtime === 'docker' ? ['image' => 'worker:1'] : [])];
    [$definition, $node] = definitionEditFixture([...$base, $field => $value]);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => [$field => null]],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe($base);
    $instance = Instance::query()->sole();
    $target = app(ProcessTargetResolver::class)->forPreparation($instance);
    $attributes = app(InstantiateProjectRuntimeDefinitionsAction::class)
        ->attributesForDefinition($instance, $definition, $target);
    expect($attributes['working_directory'])->toBe($runtime === 'docker' ? null : $target->defaultWorkingDirectory);
    expect($attributes['restart_policy'])->toBe('never');
    expect($attributes['keep_alive'])->toBeFalse();
    expect($attributes['restart_on_deploy'])->toBeTrue();
})->with([
    'Docker working directory' => ['docker', 'working_directory', '/app/custom'],
    'systemd working directory' => ['systemd', 'working_directory', '/srv/tool/custom'],
    'restart policy' => ['systemd', 'restart_policy', 'always'],
    'keep alive' => ['systemd', 'keep_alive', true],
    'restart on deploy' => ['systemd', 'restart_on_deploy', false],
    'environment file' => ['systemd', 'environment_file', '/srv/tool/custom.env'],
]);

it('clears an optional collection with an empty list', function (string $runtime, string $field, array $value): void {
    $base = ['runtime' => $runtime, 'command' => ['/usr/bin/worker'], ...($runtime === 'docker' ? ['image' => 'worker:1'] : [])];
    [$definition, $node] = definitionEditFixture([...$base, $field => $value]);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => [$field => []]],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe([...$base, $field => []]);
})->with([
    'ports' => ['docker', 'ports', ['8080:80']],
    'volumes' => ['docker', 'volumes', [['source' => 'cache', 'target' => '/cache', 'read_only' => true]]],
    'required mount paths' => ['systemd', 'required_mount_paths', ['/srv/tool/storage']],
]);

it('removes one environment key while preserving the real value of the remaining key', function (): void {
    $base = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker']];
    [$definition, $node] = definitionEditFixture([...$base, 'environment' => ['KEEP' => 'real-secret', 'REMOVE' => 'other-secret']]);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => ['environment' => ['KEEP' => '[REDACTED]']]],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe([...$base, 'environment' => ['KEEP' => 'real-secret']]);
});

it('keeps required fields and captured preset command and metadata from being cleared', function (bool $preset, array $change): void {
    $base = $preset
        ? ['runtime' => 'systemd', 'command' => VpDevPreset::definitionCommand(), 'preset' => 'vp-dev', 'preset_version' => 1]
        : ['runtime' => 'docker', 'command' => ['worker'], 'image' => 'worker:1'];
    [$definition, $node] = definitionEditFixture($base);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => $change],
    )->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect($definition->refresh()->spec)->toBe($base);
})->with([
    'required image' => [false, ['image' => null]],
    'required command' => [false, ['command' => []]],
    'preset identity' => [true, ['preset' => null]],
    'preset version' => [true, ['preset_version' => null]],
    'preset command' => [true, ['command' => []]],
]);

it('accepts the unchanged CLI full specification shape without losing omitted values', function (bool $preset, bool $versioned): void {
    $base = ['runtime' => 'systemd', 'command' => $preset ? VpDevPreset::definitionCommand() : ['/usr/bin/worker'],
        'working_directory' => '/srv/workspace', 'environment_file' => '/srv/workspace/.env',
        'environment' => ['TOKEN' => 'real-secret'], 'keep_alive' => false, 'restart_on_deploy' => false,
        ...($preset ? ['preset' => 'vp-dev', ...($versioned ? ['preset_version' => 1] : [])] : [])];
    [$definition, $node] = definitionEditFixture($base);
    $cliSpec = ['runtime' => 'systemd', 'command' => $base['command'], 'restart_policy' => 'on-failure', 'keep_alive' => true];

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => $cliSpec],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe([...$base, ...$cliSpec]);
})->with([
    'ordinary definition' => [false, false],
    'unversioned captured vp-dev definition' => [true, false],
    'versioned captured vp-dev definition' => [true, true],
]);

it('refuses an unmatched placeholder when creating a definition', function (): void {
    [$existing, $node] = definitionEditFixture(['runtime' => 'systemd', 'command' => ['/usr/bin/worker']]);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->postJson(
        "/api/v1/projects/{$existing->project_id}/process-definitions",
        ['name' => 'other', 'environments' => ['development'], 'spec' => [
            'runtime' => 'systemd', 'command' => ['/usr/bin/worker', '--api-token=[REDACTED]'],
        ]],
    )->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect(ProcessDefinition::query()->count())->toBe(1);
});

it('refuses an empty working directory instead of storing an undocumented default', function (): void {
    $original = ['runtime' => 'docker', 'image' => 'worker:1', 'command' => ['worker'], 'working_directory' => '/app/custom'];
    [$definition, $node] = definitionEditFixture($original);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => ['working_directory' => '']],
    )->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect($definition->refresh()->spec)->toBe($original);
});
