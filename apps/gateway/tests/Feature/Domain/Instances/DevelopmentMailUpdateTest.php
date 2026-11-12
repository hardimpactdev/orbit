<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\Environment\DevelopmentMailUpdate;
use App\Domain\Instances\Environment\InstanceEnvironmentImporter;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Projects\ProjectEnvironmentTemplateRefresh;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return array{Instance,Process} */
function mailUpdateFixture(): array
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => '.', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    app(InstanceContainer::class)->reserve($instance, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $mailpit = Process::query()->forceCreate(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'mailpit',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'mailpit:fixture', 'ports' => ['1025:1025']],
        'status' => 'active', 'desired_state' => 'running']);

    return [$instance, $mailpit];
}

it('renders log as smtp only in eligible new default and clone files while preserving stored values', function (?string $value, bool $eligible, string $quote): void {
    [$instance, $mailpit] = mailUpdateFixture();
    if (! $eligible) {
        $mailpit->delete();
    }
    $template = "APP_NAME=Fixture\nAPP_KEY=fixture-key\nDB_HOST=hand-choice\nMAIL_HOST=localhost\nMAIL_PORT=2525\nVITE_APP_NAME=\"\${APP_NAME}\"\n";
    if ($value !== null) {
        $template .= 'MAIL_MAILER='.$quote.$value.$quote."\n";
    }
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($instance->project, str_repeat('a', 40), 'fixture', $template);
    $entries = $instance->project->environmentTemplate()->sole()->entries;
    $environment = app(DevelopmentCreateEnvironment::class);
    $environment->admit($instance, $instance->project);
    $stored = app(InstanceEnvironmentStore::class)->values($instance);
    $created = $environment->content($instance);
    $clone = Instance::query()->create(['node_id' => $instance->node_id, 'project_id' => $instance->project_id, 'name' => 'clone',
        'checkout_path' => '/workspace/clone', 'root' => '.', 'status' => 'active', 'source_is_laravel' => false]);
    app(InstanceContainer::class)->reserve($clone, $instance->node, $instance);
    $environment->admit($clone, $instance->project, source: $instance);
    expect($environment->content($clone))->toBe($created)
        ->and($created)->toContain('MAIL_HOST="localhost"', 'MAIL_PORT="2525"', 'DB_HOST="hand-choice"', 'VITE_APP_NAME="${APP_NAME}"')
        ->and(app(InstanceEnvironmentStore::class)->values($instance))->toBe($stored)
        ->and($instance->project->environmentTemplate()->sole()->entries)->toBe($entries)
        ->and($environment->mailKeys($instance))->toBe($eligible && $value === 'log' ? ['MAIL_MAILER'] : []);
    if ($value === null) {
        expect($created)->not->toContain('MAIL_MAILER=');
    } else {
        expect($created)->toContain('MAIL_MAILER="'.($eligible && $value === 'log' ? 'smtp' : $value).'"');
    }
})->with(['log', 'smtp', 'ses', 'LOG', '', null])->with([true, false])->with(['bare' => '', 'double' => '"', 'single' => "'"]);

it('leaves production checkout and no-endpoint rendering alone', function (string $case): void {
    [$instance, $mailpit] = mailUpdateFixture();
    match ($case) {
        'production' => $instance->node->roles()->update(['role' => RoleName::AppProd]),
        'checkout' => $instance->runtime()->delete(),
        'no endpoint' => $mailpit->delete(),
    };
    app(InstanceEnvironmentStore::class)->seedForCreate($instance, ['MAIL_MAILER' => 'log']);
    expect(app(DevelopmentCreateEnvironment::class)->content($instance))->toContain('MAIL_MAILER="log"');
})->with(['production', 'checkout', 'no endpoint']);

it('uses the stored Instance value before the Project template without rewriting either', function (string $quote): void {
    [$instance] = mailUpdateFixture();
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($instance->project, str_repeat('a', 40), 'fixture', "MAIL_MAILER=ses\n");
    $values = app(InstanceEnvironmentImporter::class)->parse('MAIL_MAILER='.$quote.'log'.$quote."\n");
    expect($values)->toBe(['MAIL_MAILER' => 'log']);
    app(InstanceEnvironmentStore::class)->seedForCreate($instance, $values);
    $environment = app(DevelopmentCreateEnvironment::class);
    $environment->admit($instance, $instance->project);
    expect($environment->content($instance))->toContain('MAIL_MAILER="smtp"')
        ->and(app(InstanceEnvironmentStore::class)->values($instance)['MAIL_MAILER'])->toBe('log');
    $clone = Instance::query()->create(['node_id' => $instance->node_id, 'project_id' => $instance->project_id, 'name' => 'stored-clone',
        'checkout_path' => '/workspace/stored-clone', 'root' => '.', 'status' => 'active', 'source_is_laravel' => false]);
    app(InstanceContainer::class)->reserve($clone, $instance->node, $instance);
    app(InstanceEnvironmentStore::class)->seedForCreate($clone, $values);
    $environment->admit($clone, $instance->project, source: $instance);
    expect($environment->content($clone))->toContain('MAIL_MAILER="smtp"')
        ->and(app(InstanceEnvironmentStore::class)->values($clone)['MAIL_MAILER'])->toBe('log');
})->with(['bare' => '', 'double' => '"', 'single' => "'"]);

it('admits a scoped secret-free Operation through the authenticated API', function (): void {
    [$instance] = mailUpdateFixture();
    $instance->node->accessibleNodes()->syncWithoutDetaching([$instance->node_id]);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
        ->postJson('/api/v1/instances/'.$instance->id.'/mail')
        ->assertAccepted()->assertJsonPath('data.kind', DevelopmentMailUpdate::Kind);
    $operation = Operation::query()->where('kind', DevelopmentMailUpdate::Kind)->sole();
    $control = containerDefaultNode('control', '10.44.0.209', false);
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $input = app(DevelopmentMailUpdate::class)->input($operation);
    expect($operation->lock_key)->toBe(OperationStore::lockKey('instance', $instance->id))
        ->and($input['intent']['container'])->toBe($operation->input['container'])
        ->and($operation->input['container']['mail']['port'])->toBe(1025)
        ->and($operation->input['container']['mail_units'])->toHaveCount(4)
        ->and($input)->not->toHaveKey('execution_secrets')
        ->and(app(DirectOperation::class)->workflowInputs($operation)['execution_secrets'])->toEqual((object) [])
        ->and($input['intent'])->toHaveKey('ssh_execution');
});

it('requires the caller Node access edge', function (): void {
    [$instance] = mailUpdateFixture();
    $caller = containerDefaultNode('caller', '10.44.0.8', false);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/instances/'.$instance->id.'/mail')->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect(Operation::query()->where('kind', DevelopmentMailUpdate::Kind)->exists())->toBeFalse();
});

it('selects FrankenPHP and PHP worker kinds and excludes unrelated active Processes', function (): void {
    [$instance] = mailUpdateFixture();
    $instance->project->update(['type' => ProjectType::LaravelApp, 'php_version' => '8.5']);
    $processes = [];
    foreach ([['queue', ['php', 'artisan', 'queue:work']], ['horizon', ['php', 'artisan', 'horizon']],
        ['javascript', ['node', 'watch.js']], ['php-other', ['php', 'custom.php']],
        ['name-is-not-kind', ['node', 'artisan', 'queue:work']], ['queue-stopped', ['php', 'artisan', 'queue:work']]] as [$name, $command]) {
        $processes[$name] = $instance->processes()->create(['name' => $name, 'runtime' => 'systemd', 'runtime_config' => ['command' => $command],
            'status' => 'active', 'desired_state' => 'running', 'working_directory' => '/srv/workspace']);
    }
    $processes['queue-stopped']->update(['desired_state' => 'stopped']);
    $instance->update(['vite_port' => 5173]);
    $processes['vp-dev'] = $instance->processes()->create(['name' => 'vite', 'runtime' => 'systemd',
        'runtime_config' => ['preset' => 'vp-dev', 'command' => VpDevPreset::command(5173)],
        'status' => 'active', 'desired_state' => 'running', 'working_directory' => $instance->checkout_path]);
    $operation = app(DevelopmentMailUpdate::class)->start($instance, null, null);
    $units = $operation->input['mail_refresh']['units'];
    expect($units)->toHaveCount(3)->toContain(InstanceContainer::FrankenPhpUnit);
    foreach ($processes as $name => $process) {
        $unit = app(SystemdProcessRenderer::class)->unitName($process);
        if (in_array($name, ['queue', 'horizon'], true)) {
            expect($units)->toContain($unit);
        } else {
            expect($units)->not->toContain($unit);
        }
    }
});

it('refuses unsupported or busy placement before starting work', function (string $case, string $code): void {
    [$instance, $mailpit] = mailUpdateFixture();
    match ($case) {
        'checkout' => $instance->runtime()->delete(),
        'production' => $instance->node->roles()->update(['role' => RoleName::AppProd]),
        'no mailpit' => $mailpit->delete(),
        'stopped' => $instance->runtime()->update(['power_state' => 'stopped']),
        'lifecycle' => $instance->update(['status' => 'checkout_prepared']),
        'process' => Operation::query()->create(['kind' => 'process.restart', 'target_type' => 'process',
            'target_id' => $instance->processes()->create(['name' => 'worker', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['true']], 'status' => 'active'])->id,
            'status' => 'running', 'input' => [], 'state' => []]),
        default => Operation::query()->create(['kind' => $case, 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'lock_key' => OperationStore::lockKey('instance', $instance->id), 'status' => 'running', 'input' => [], 'state' => []]),
    };
    expect(fn () => app(DevelopmentMailUpdate::class)->start($instance, null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect(Operation::query()->where('kind', DevelopmentMailUpdate::Kind)->count())->toBe(0);
})->with([
    ['checkout', 'operation.kind_unavailable'], ['production', 'instance.host_unavailable'], ['no mailpit', 'instance.host_unavailable'],
    ['stopped', 'process.target_inactive'], ['lifecycle', 'instance.lifecycle_busy'], ['process', 'instance.lifecycle_busy'],
    ['instance.deploy', 'operation.target_busy'], ['instance.migrate', 'operation.target_busy'], ['instance.destroy', 'operation.target_busy'],
]);

it('holds Gateway release idle until mail work settles', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $operation = Operation::query()->create(['kind' => DevelopmentMailUpdate::Kind, 'target_type' => 'instance', 'target_id' => 1,
        'status' => 'running', 'input' => [], 'state' => []]);
    Sleep::whenFakingSleep(fn () => $operation->update(['status' => 'succeeded']));
    app(WaitForGatewayReleaseIdleAction::class)->execute();
    Sleep::assertSleptTimes(1);
});

it('projects each public mailer outcome and the successful conflict skip without storing values', function (array $result): void {
    [$instance] = mailUpdateFixture();
    $store = app(InstanceEnvironmentStore::class);
    $store->seedForCreate($instance, ['DB_HOST' => 'stored-hand-choice', 'MAIL_MAILER' => 'log']);
    $stored = $store->values($instance);
    $operation = app(DevelopmentMailUpdate::class)->start($instance, null, null);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'mail-update-proof']])->save();
    $data = ['status' => 'succeeded', 'run_id' => 'mail-update-proof', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => $result];
    expect(app(DevelopmentMailUpdate::class)->apply($operation, $data)->result)->toBe($result)
        ->and($store->values($instance))->toBe($stored);
})->with([
    [['mail' => ['status' => 'applied', 'ports' => [1025, 2525]], 'mailer' => ['status' => 'changed', 'lines' => [2]]]],
    [['mail' => ['status' => 'unchanged', 'ports' => [1025, 2525]], 'mailer' => ['status' => 'left_as_written', 'lines' => [2, 8]]]],
    [['mail' => ['status' => 'unchanged', 'ports' => [1025, 2525]], 'mailer' => ['status' => 'not_present']]],
    [['mail' => ['status' => 'unchanged', 'ports' => [1025, 2525]], 'mailer' => ['status' => 'already_smtp']]],
    [['mail' => ['status' => 'skipped', 'reason' => 'mail_port_in_use', 'ports' => [1025, 2525]]]],
    [['mail' => ['status' => 'skipped', 'reason' => 'mail_unit_conflict', 'ports' => [1025, 2525]]]],
]);

it('names the mailer key in the create result and records it as owned without changing stored log', function (): void {
    [$fixture] = mailUpdateFixture();
    $node = $fixture->node;
    $project = $fixture->project;
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($project, str_repeat('a', 40), 'fixture', "MAIL_MAILER=log\n");
    $fixture->runtime()->delete();
    $fixture->delete();
    $control = containerDefaultNode('control', '10.44.0.209', false);
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $operation = containerCreate($project, $node);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'mail-create-proof']])->save();
    $data = ['status' => 'succeeded', 'run_id' => 'mail-create-proof', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['ready' => true, 'instance_id' => $operation->target_id, 'commit' => str_repeat('a', 40)]];
    $result = app(DevelopmentInstanceCreateProjection::class)->apply($operation, $data);
    $instance = Instance::query()->findOrFail($operation->target_id);
    expect($result->result['mail_keys_set'])->toBe(['MAIL_MAILER'])
        ->and($instance->environment_owned_keys)->toContain('MAIL_MAILER')
        ->and(app(InstanceEnvironmentStore::class)->values($instance)['MAIL_MAILER'])->toBe('log');
});
