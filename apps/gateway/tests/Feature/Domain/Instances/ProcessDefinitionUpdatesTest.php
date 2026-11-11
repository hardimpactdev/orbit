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
use App\Models\ScheduleDefinition;
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

it('returns 422 for redaction markers without main replay handling without changing the row', function (array $change, string $field): void {
    $original = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker', '--api-token=synthetic-real-secret'],
        'environment' => ['TOKEN' => 'synthetic-environment-secret'], 'working_directory' => '/srv/tool/custom',
        'restart_on_deploy' => false];
    [$definition, $node] = definitionEditFixture($original);
    $before = $definition->fresh()->getRawOriginal();

    $response = $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => $change],
    );

    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($response->json('error.details')[$field])->toBe([
        'Redacted values cannot be submitted. Send only the fields you want to change; omit the redacted ones.',
    ]);
    expect($definition->refresh()->getRawOriginal())->toBe($before);
})->with([
    'reviewer command replay' => [['runtime' => 'systemd', 'command' => ['/usr/bin/worker', '--api-token=[REDACTED]']], 'spec.command.1'],
    'embedded environment marker' => [['environment' => ['TOKEN' => 'prefix[REDACTED]suffix']], 'spec.environment.TOKEN'],
    'reviewer runtime drops omitted fields' => [['runtime' => '[REDACTED]', 'command' => ['/usr/bin/worker'], 'keep_alive' => true], 'spec.runtime'],
    'nested volume source' => [['volumes' => [['source' => '[REDACTED]', 'target' => '/cache']]], 'spec.volumes.0.source'],
    'nested volume boolean' => [['volumes' => [['source' => 'cache', 'target' => '/cache', 'read_only' => '[REDACTED]']]], 'spec.volumes.0.read_only'],
    'embedded marker' => [['working_directory' => '/srv/[REDACTED]/custom'], 'spec.working_directory'],
    'port' => [['ports' => ['[REDACTED]']], 'spec.ports.0'],
    'environment file' => [['environment_file' => '[REDACTED]'], 'spec.environment_file'],
    'required mount path' => [['required_mount_paths' => ['[REDACTED]']], 'spec.required_mount_paths.0'],
    'image' => [['image' => '[REDACTED]'], 'spec.image'],
    'restart policy' => [['restart_policy' => '[REDACTED]'], 'spec.restart_policy'],
    'preset' => [['preset' => '[REDACTED]'], 'spec.preset'],
    'preset version' => [['preset_version' => '[REDACTED]'], 'spec.preset_version'],
    'keep alive' => [['keep_alive' => '[REDACTED]'], 'spec.keep_alive'],
    'restart on deploy' => [['restart_on_deploy' => '[REDACTED]'], 'spec.restart_on_deploy'],
    'malformed environment containing marker' => [['environment' => '[REDACTED]'], 'spec.environment'],
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

it('replaces a sent environment map while keeping a replayed value exactly as on main', function (string $retained): void {
    $base = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker']];
    [$definition, $node] = definitionEditFixture([...$base, 'environment' => ['KEEP' => 'real-secret', 'REMOVE' => 'other-secret']]);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => ['environment' => ['KEEP' => $retained]]],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe([...$base, 'environment' => ['KEEP' => 'real-secret']]);
})->with(['literal value' => 'real-secret', 'main environment replay' => '[REDACTED]']);

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

it('refuses an empty working directory instead of storing an undocumented default', function (): void {
    $original = ['runtime' => 'docker', 'image' => 'worker:1', 'command' => ['worker'], 'working_directory' => '/app/custom'];
    [$definition, $node] = definitionEditFixture($original);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => ['working_directory' => '']],
    )->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect($definition->refresh()->spec)->toBe($original);
});

it('keeps each absent optional specification field', function (string $runtime, string $field, mixed $value): void {
    $original = ['runtime' => $runtime, 'command' => ['/usr/bin/worker'],
        ...($runtime === 'docker' ? ['image' => 'worker:1'] : []), $field => $value];
    [$definition, $node] = definitionEditFixture($original);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => (object) []],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe($original);
})->with([
    'image' => ['docker', 'image', 'worker:1'],
    'working directory' => ['systemd', 'working_directory', '/srv/tool/custom'],
    'restart policy' => ['systemd', 'restart_policy', 'always'],
    'keep alive' => ['systemd', 'keep_alive', true],
    'restart on deploy' => ['systemd', 'restart_on_deploy', false],
    'environment file' => ['systemd', 'environment_file', '/srv/tool/custom.env'],
    'environment' => ['systemd', 'environment', ['TOKEN' => 'synthetic-secret']],
    'ports' => ['docker', 'ports', ['8080:80']],
    'volumes' => ['docker', 'volumes', [['source' => 'cache', 'target' => '/cache', 'read_only' => true]]],
    'required mount paths' => ['systemd', 'required_mount_paths', ['/srv/tool/storage']],
]);

it('removes one collection item by sending the remaining list', function (string $runtime, string $field, array $items): void {
    $base = ['runtime' => $runtime, 'command' => ['/usr/bin/worker'], ...($runtime === 'docker' ? ['image' => 'worker:1'] : [])];
    [$definition, $node] = definitionEditFixture([...$base, $field => $items]);
    $remaining = [$items[1]];

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => [$field => $remaining]],
    )->assertOk();

    expect($definition->refresh()->spec)->toBe([...$base, $field => $remaining]);
})->with([
    'ports' => ['docker', 'ports', ['8080:80', '8081:81']],
    'volumes' => ['docker', 'volumes', [['source' => 'old', 'target' => '/old'], ['source' => 'cache', 'target' => '/cache', 'read_only' => false]]],
    'required mount paths' => ['systemd', 'required_mount_paths', ['/srv/tool/old', '/srv/tool/storage']],
]);

it('requires the new runtime fields when converting to Docker', function (): void {
    $original = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker'], 'working_directory' => '/srv/tool/custom'];
    [$definition, $node] = definitionEditFixture($original);
    $url = "/api/v1/projects/{$definition->project_id}/process-definitions/worker";
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    $this->putJson($url, ['name' => 'worker', 'environments' => ['development'], 'spec' => ['runtime' => 'docker']])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($definition->refresh()->spec)->toBe($original);
    $replacement = ['runtime' => 'docker', 'image' => 'worker:1', 'command' => ['worker']];
    $this->putJson($url, ['name' => 'worker', 'environments' => ['development'], 'spec' => $replacement])->assertOk();
    expect($definition->refresh()->spec)->toBe($replacement);
});

it('keeps main item, list and MCP response behavior including public argv', function (): void {
    $command = ['/usr/bin/worker', '--public-key=demo-public-value', '--token-count=100', '--api-token=synthetic-real-secret'];
    [$definition, $node] = definitionEditFixture(['runtime' => 'systemd', 'command' => $command,
        'environment' => ['TOKEN' => 'synthetic-environment-secret']]);
    $url = "/api/v1/projects/{$definition->project_id}/process-definitions";
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    $this->getJson($url.'/worker')->assertOk()->assertJsonPath('data.spec.command', $command)
        ->assertJsonPath('data.spec.environment.TOKEN', '[REDACTED]');
    $this->getJson($url)->assertOk()->assertJsonMissingPath('data.0.spec.command')
        ->assertJsonPath('data.0.spec.environment.TOKEN', '[REDACTED]');
    $this->putJson($url.'/worker', ['name' => 'worker', 'environments' => ['development'], 'spec' => ['keep_alive' => true]])
        ->assertOk()->assertJsonPath('data.spec.command', $command);
    $manifest = collect(ToolManifest::default()->definitions())->firstWhere('name', 'project-process-definition-update');
    $tool = new ApiOperationTool($manifest, app(ApiDispatcher::class));
    $caller = Request::create('/mcp', 'POST', server: ['REMOTE_ADDR' => $node->wireguard_ip]);
    $response = $tool->handle(new Laravel\Mcp\Request(['project' => $definition->project_id, 'process_definition' => 'worker',
        'name' => 'worker', 'environments' => ['development'], 'spec' => ['keep_alive' => false]]), $caller);
    $data = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR)['data'];
    expect($data['spec']['command'])->toBe($command);
    expect($data['spec']['environment']['TOKEN'])->toBe('[REDACTED]');
    expect($definition->refresh()->spec['command'])->toBe($command);
});

it('keeps Schedule definition read then update behavior on main', function (): void {
    [$process, $node] = definitionEditFixture(['runtime' => 'systemd', 'command' => ['/usr/bin/worker']]);
    $command = '/usr/bin/worker --api-token=synthetic-real-secret';
    $schedule = ScheduleDefinition::query()->create(['project_id' => $process->project_id, 'name' => 'nightly',
        'environments' => ['production'], 'spec' => ['command' => $command, 'calendar' => 'daily', 'timeout_seconds' => 60]]);
    $url = "/api/v1/projects/{$process->project_id}/schedule-definitions/nightly";
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    $item = $this->getJson($url)->assertOk()->assertJsonPath('data.spec.command', $command)->json('data');
    $item['spec']['timeout_seconds'] = 120;
    $body = ['name' => $item['name'], 'environments' => $item['environments'], 'spec' => $item['spec']];
    $this->putJson($url, $body)->assertOk()->assertJsonPath('data.spec.command', $command);
    expect($schedule->refresh()->spec)->toBe(['command' => $command, 'calendar' => 'daily', 'timeout_seconds' => 120]);

    $before = $schedule->getRawOriginal();
    $this->putJson($url, ['name' => 'nightly', 'environments' => ['production'], 'spec' => ['timeout_seconds' => 180]])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($schedule->refresh()->getRawOriginal())->toBe($before);
});

it('keeps the main refusal for a replayed marker at an unknown environment key', function (): void {
    [$definition, $node] = definitionEditFixture(['runtime' => 'systemd', 'command' => ['/usr/bin/worker'],
        'environment' => ['TOKEN' => 'synthetic-secret']]);
    $before = $definition->fresh()->getRawOriginal();

    $response = $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$definition->project_id}/process-definitions/worker",
        ['name' => 'worker', 'environments' => ['development'], 'spec' => ['environment' => ['UNKNOWN' => '[REDACTED]']]],
    );

    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($response->json('error.details')['spec.environment'])->toBe(['A redacted value must name an existing environment key.']);
    expect($definition->refresh()->getRawOriginal())->toBe($before);
});
