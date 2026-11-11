<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\Environment\DevelopmentMailEnvironment;
use App\Domain\Instances\Environment\DevelopmentMailUpdate;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectEnvironmentTemplateRefresh;
use App\Domain\Shared\ResourceOperationException;
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
    config(['instances.mailpit' => ['process' => 'mailpit', 'port' => 1025]]);
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return array{Instance,Process} */
function mailFixture(): array
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => '.', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    app(InstanceContainer::class)->reserve($instance, $node)->update(['power_state' => 'running']);
    $mailpit = Process::query()->forceCreate(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'mailpit',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'mailpit:fixture', 'ports' => ['1025:1025', '8025:8025']],
        'status' => 'active', 'desired_state' => 'running']);

    return [$instance, $mailpit];
}

it('takes over stock mail and clears stale authentication and TLS', function (array $mail): void {
    $result = DevelopmentMailEnvironment::resolve([...$mail, 'MAIL_USERNAME' => 'old', 'MAIL_PASSWORD' => 'old',
        'MAIL_SCHEME' => 'smtps', 'MAIL_ENCRYPTION' => 'tls'], '10.253.0.1', 1025);
    expect($result)->toBe(['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => '10.253.0.1', 'MAIL_PORT' => '1025',
        'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_ENCRYPTION' => '', 'MAIL_SCHEME' => '']);
})->with([
    'absent' => [[]], 'log' => [['MAIL_MAILER' => 'log']], 'old host' => [['MAIL_HOST' => '10.6.0.7', 'MAIL_PORT' => '25']],
    'localhost 1025' => [['MAIL_HOST' => 'localhost', 'MAIL_PORT' => '1025']],
    'localhost 2525' => [['MAIL_HOST' => 'localhost', 'MAIL_PORT' => '2525']],
    '127 1025 smtp' => [['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1025', 'MAIL_MAILER' => 'smtp']],
    '127 2525' => [['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '2525']],
    'template smtp loopback' => [['MAIL_HOST' => 'localhost', 'MAIL_MAILER' => 'smtp']],
]);

it('keeps every mail key for an own choice even beside log or stale credentials', function (array $mail): void {
    expect(DevelopmentMailEnvironment::resolve($mail, '10.253.0.1', 1025))->toBe([]);
})->with([
    'host beats log' => [['MAIL_HOST' => 'smtp.own.test', 'MAIL_MAILER' => 'log']],
    'ses' => [['MAIL_MAILER' => 'ses', 'MAIL_HOST' => 'localhost']],
    'postmark' => [['MAIL_MAILER' => 'postmark']],
    'url' => [['MAIL_URL' => 'smtp://fixture:fixture@own.test']],
    'reference' => [['MAIL_HOST' => '${SMTP_HOST}']],
    'custom loopback port' => [['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'localhost', 'MAIL_PORT' => '465']],
]);

it('renders the same defaults for new containers and clones without changing template or stored values', function (): void {
    [$instance] = mailFixture();
    $template = "APP_NAME=Fixture\nAPP_KEY=fixture-key\nMAIL_HOST=localhost\nMAIL_PORT=2525\nVITE_APP_NAME=\"\${APP_NAME}\"\n";
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
        ->and($created)->toContain('MAIL_HOST="10.253.0.1"', 'MAIL_PORT="1025"', 'MAIL_PASSWORD=""', 'VITE_APP_NAME="${APP_NAME}"')
        ->and(app(InstanceEnvironmentStore::class)->values($instance))->toBe($stored)
        ->and($instance->project->environmentTemplate()->sole()->entries)->toBe($entries);
});

it('requires the actual configured running Docker publication for the values', function (): void {
    [$instance, $mailpit] = mailFixture();
    $policy = app(DevelopmentMailEnvironment::class);
    expect($policy->endpoint($instance->node))->toBe(['host' => '10.253.0.1', 'port' => 1025, 'docker' => true]);
    foreach ([['status' => 'failed'], ['desired_state' => 'stopped'], ['runtime' => 'systemd'],
        ['name' => 'other-mailpit'], ['runtime_config' => ['ports' => ['127.0.0.1:1025:1025']]]] as $change) {
        $before = $mailpit->getAttributes();
        $mailpit->update($change);
        expect($policy->endpoint($instance->node))->toBeNull();
        $mailpit->setRawAttributes($before)->save();
    }
});

it('gives checkouts production and non-Mailpit Nodes no mail projection', function (): void {
    [$instance, $mailpit] = mailFixture();
    $policy = app(DevelopmentMailEnvironment::class);
    $mailpit->update(['desired_state' => 'stopped']);
    expect($policy->forInstance($instance, []))->toBe([]);
    $mailpit->update(['desired_state' => 'running']);
    $instance->node->roles()->update(['role' => RoleName::AppProd]);
    $instance->unsetRelation('node');
    expect($policy->forInstance($instance, []))->toBe([]);
    $instance->node->roles()->update(['role' => RoleName::AppDev]);
    $instance->runtime()->delete();
    expect($policy->forInstance($instance, []))->toBe([]);
});

it('admits a value-free scoped mail Operation without environment observation or confirmation', function (): void {
    [$instance] = mailFixture();
    $instance->node->accessibleNodes()->syncWithoutDetaching([$instance->node_id]);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
        ->postJson('/api/v1/instances/'.$instance->id.'/mail')
        ->assertAccepted()->assertJsonPath('data.kind', DevelopmentMailUpdate::Kind);
    $operation = Operation::query()->where('kind', DevelopmentMailUpdate::Kind)->sole();
    expect($operation->lock_key)->toBe(OperationStore::lockKey('instance', $instance->id))
        ->and($operation->input['mail']['name'])->toBe($instance->runtime()->sole()->container_name)
        ->and($operation->input['mail']['backup'])->toBe('/var/lib/orbit/instance-mail/environment.backup')
        ->and($operation->input)->not->toHaveKey('direct_environment');
});

it('refuses unsupported or busy placement before starting work', function (string $case, string $code): void {
    [$instance, $mailpit] = mailFixture();
    match ($case) {
        'checkout' => $instance->runtime()->delete(),
        'production' => $instance->node->roles()->update(['role' => RoleName::AppProd]),
        'no mailpit' => $mailpit->delete(),
        'stopped' => $instance->runtime()->update(['power_state' => 'stopped']),
        'lifecycle' => $instance->update(['status' => 'checkout_prepared']),
        'operation' => Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'lock_key' => OperationStore::lockKey('instance', $instance->id), 'status' => 'running', 'input' => [], 'state' => []]),
    };
    expect(fn () => app(DevelopmentMailUpdate::class)->start($instance, null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect(Operation::query()->where('kind', DevelopmentMailUpdate::Kind)->count())->toBe(0);
})->with([
    ['checkout', 'operation.kind_unavailable'], ['production', 'instance.host_unavailable'], ['no mailpit', 'instance.host_unavailable'],
    ['stopped', 'process.target_inactive'], ['lifecycle', 'instance.lifecycle_busy'], ['operation', 'operation.target_busy'],
]);

it('holds Gateway release idle until mail work settles', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $operation = Operation::query()->create(['kind' => DevelopmentMailUpdate::Kind, 'target_type' => 'instance', 'target_id' => 1,
        'status' => 'running', 'input' => [], 'state' => []]);
    Sleep::whenFakingSleep(fn () => $operation->update(['status' => 'succeeded']));
    app(WaitForGatewayReleaseIdleAction::class)->execute();
    Sleep::assertSleptTimes(1);
});

it('reports mail takeover names from the common create and development-clone result projector', function (): void {
    [$fixture] = mailFixture();
    $node = $fixture->node;
    $project = $fixture->project;
    $fixture->runtime()->delete();
    $fixture->delete();
    $control = containerDefaultNode('control', '10.44.0.209', false);
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $operation = containerCreate($project, $node);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'mail-create-proof']])->save();
    $data = ['status' => 'succeeded', 'run_id' => 'mail-create-proof', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['ready' => true, 'instance_id' => $operation->target_id, 'commit' => str_repeat('a', 40)]];
    $result = app(DevelopmentInstanceCreateProjection::class)->apply($operation, $data);
    expect($result->result['mail_keys_taken_over'])->toBe(DevelopmentMailEnvironment::Keys)
        ->and(Instance::query()->findOrFail($operation->target_id)->environment_owned_keys)->toContain(...DevelopmentMailEnvironment::Keys);
});

it('records only takeover names after mail completion and preserves stored non-mail values', function (): void {
    [$instance] = mailFixture();
    app(InstanceEnvironmentStore::class)->seedForCreate($instance, ['DB_HOST' => 'stored-hand-choice', 'MAIL_MAILER' => 'log']);
    $operation = app(DevelopmentMailUpdate::class)->start($instance, null, null);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'mail-update-proof']])->save();
    $data = ['status' => 'succeeded', 'run_id' => 'mail-update-proof', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['mail_keys_taken_over' => DevelopmentMailEnvironment::Keys,
            'changed_key_count' => 7, 'changed' => true, 'reason' => 'mail_defaults_applied']];
    $result = app(DevelopmentMailUpdate::class)->apply($operation, $data);
    expect($result->result['mail_keys_taken_over'])->toBe(DevelopmentMailEnvironment::Keys)
        ->and($result->result['changed_key_count'])->toBe(7)
        ->and(app(InstanceEnvironmentStore::class)->values($instance))->toBe(['DB_HOST' => 'stored-hand-choice', 'MAIL_MAILER' => 'log'])
        ->and($instance->refresh()->environment_owned_keys)->toBe(DevelopmentMailEnvironment::Keys);
    app(DevelopmentMailUpdate::class)->apply($result, $data);
    expect($instance->refresh()->environment_owned_keys)->toBe(DevelopmentMailEnvironment::Keys);
});
