<?php

declare(strict_types=1);

use App\Domain\Instances\DevelopmentMail;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return array{Instance,Process} */
function forwarderFixture(): array
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => '.', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    app(InstanceContainer::class)->reserve($instance, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $mailpit = Process::query()->forceCreate(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'mailpit',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'mailpit:fixture', 'ports' => ['1025:1025', '8025:8025']],
        'status' => 'active', 'desired_state' => 'running']);

    return [$instance, $mailpit];
}

it('admits exactly the public SMTP target in the own ACL and bridge for defaults and clones', function (): void {
    [$instance] = forwarderFixture();
    $policy = app(InstanceContainer::class);
    $endpoint = ['host' => '10.253.0.1', 'port' => 1025, 'docker' => true];
    foreach ([$instance, tap($instance->replicate(), function (Instance $copy): void {
        $copy->name = 'clone';
        $copy->checkout_path = '/workspace/clone';
        $copy->save();
    })] as $candidate) {
        $runtime = $candidate->runtime()->first() ?? $policy->reserve($candidate, $candidate->node, $instance);
        $intent = $policy->intent($candidate, $runtime);
        expect($intent['mail'])->toBe($endpoint)
            ->and($intent['acl_rules']['egress'])->toBe([['action' => 'allow', 'destination' => '10.253.0.1/32',
                'protocol' => 'tcp', 'destination_port' => '1025', 'state' => 'enabled', 'description' => 'Orbit development SMTP']])
            ->and($intent['bridge_endpoints'])->toBe([$endpoint])
            ->and($intent['firewall'])->toHaveCount($candidate->is($instance) ? 0 : 2);
        $units = $intent['mail_units'];
        expect(array_column($units, 'name'))->toBe(['orbit-mail-1025.socket', 'orbit-mail-1025.service', 'orbit-mail-2525.socket', 'orbit-mail-2525.service']);
        foreach ([0 => 1025, 2 => 2525] as $index => $port) {
            expect($units[$index]['content'])->toContain('ListenStream=127.0.0.1:'.$port, 'WantedBy=sockets.target')->not->toContain('::1')
                ->and($units[$index + 1]['content'])->toContain('ExecStart=/usr/lib/systemd/systemd-socket-proxyd 10.253.0.1:1025');
        }
    }
    expect($policy->nodeEndpoints($instance->node))->toBe([$endpoint]);
});

it('requires the configured active desired-running Docker Node Process and reachable TCP publication', function (array $change): void {
    [$instance, $mailpit] = forwarderFixture();
    $mailpit->forceFill($change)->save();
    expect(app(DevelopmentMail::class)->forInstance($instance))->toBeNull();
    $intent = app(InstanceContainer::class)->intent($instance, $instance->runtime()->sole());
    expect($intent['acl_rules']['egress'])->toBe([])->and($intent['mail_units'])->toBe([]);
})->with([
    'failed' => [['status' => 'failed']], 'stopped intent' => [['desired_state' => 'stopped']],
    'systemd' => [['runtime' => 'systemd']], 'other name' => [['name' => 'other']],
    'Instance Process' => [['owner_type' => Instance::class]],
    'loopback publication' => [['runtime_config' => ['ports' => ['127.0.0.1:1025:1025']]]],
    'udp' => [['runtime_config' => ['ports' => ['1025:1025/udp']]]],
    'different port' => [['runtime_config' => ['ports' => ['1026:1025']]]],
]);

it('excludes checkout production and Nodes without the main Mailpit', function (): void {
    [$instance, $mailpit] = forwarderFixture();
    $policy = app(DevelopmentMail::class);
    $mailpit->delete();
    expect($policy->forInstance($instance))->toBeNull();
    $mailpit->save();
    $instance->node->roles()->update(['role' => RoleName::AppProd]);
    $instance->unsetRelation('node');
    expect($policy->forInstance($instance))->toBeNull();
    $instance->node->roles()->update(['role' => RoleName::AppDev]);
    $instance->unsetRelation('node');
    $instance->runtime()->delete();
    expect($policy->forInstance($instance))->toBeNull();
});

it('uses the one configured endpoint when its public port or gateway changes', function (): void {
    [$instance, $mailpit] = forwarderFixture();
    config(['instances.mailpit.port' => 2025]);
    $mailpit->update(['runtime_config' => ['ports' => ['2025:1025/tcp']]]);
    $instance->node->instance_bridge = '10.253.8.0/24';
    $endpoint = app(DevelopmentMail::class)->forInstance($instance);
    expect($endpoint)->toBe(['host' => '10.253.8.1', 'port' => 2025, 'docker' => true])
        ->and(app(DevelopmentMail::class)->units($endpoint)[1]['content'])->toContain('10.253.8.1:2025');
});

it('refuses a Mailpit port configured in the development band by name instead of hiding mail', function (): void {
    [$instance] = forwarderFixture();
    config(['instances.mailpit.port' => 5180]);

    expect(fn () => app(DevelopmentMail::class)->endpoint($instance->node))
        ->toThrow(ResourceOperationException::class, 'development band 5173-5199');

    config(['instances.mailpit.port' => 1025]);
    expect(app(DevelopmentMail::class)->endpoint($instance->node))->toBe(['host' => '10.253.0.1', 'port' => 1025, 'docker' => true]);
});
