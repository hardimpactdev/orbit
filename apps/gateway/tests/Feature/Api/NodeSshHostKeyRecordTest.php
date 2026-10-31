<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitWorkerKnownHostsAction;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    Node::unguarded(fn () => Node::query()->create(['id' => 209, 'name' => 'control-worker', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit', 'platform' => 'linux']));
    config(['swamp.run_token' => 'fixture.fake']);
    $this->gateway = $this->markAsGateway(FleetFixtures::node('pin-gateway'));
    $this->node = FleetFixtures::node('first-pin', managed: false);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->path = "/api/v1/nodes/{$this->node->id}/ssh-host-key";
    $this->input = ['host' => $this->node->wireguard_ip, 'type' => 'ssh-ed25519',
        'value' => 'AAAAC3NzaC1lZDI1NTE5AAAAIK7nvxkT+/UzdE5QYF4mJ3LD3SdcbKh3k2SiwlUOt6ak'];
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    app()->bind(WorkerKnownHostsPublisher::class, AdmitWorkerKnownHostsAction::class);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
});

it('records only the first public pin and tracks the normal API mutation without host effects', function (): void {
    $requestId = (string) Str::uuid();
    $before = $this->node->fresh()->getAttributes();
    $response = $this->withHeader('X-Orbit-Request-Id', $requestId)->postJson($this->path, $this->input)->assertOk()
        ->assertJsonPath('data.ssh_host_fingerprint', 'SHA256:KqUQInmfyojqinKC8AHVRdlFwNydb5lv4b8hom7Y4+s')
        ->assertJsonPath('meta.request_id', $requestId);
    $this->node->refresh();
    expect($this->node->only(['ssh_host_key_type', 'ssh_host_key']))->toBe(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $this->input['value']]);
    foreach (array_diff(array_keys($before), ['ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint', 'updated_at']) as $field) {
        expect($this->node->getRawOriginal($field))->toBe($before[$field]);
    }
    expect(app(SwampSshExecutionInput::class)->forNode($this->node)['target']['host_keys'])->toBe(['ssh-ed25519 '.$this->input['value']]);
    $activity = Activity::query()->where('request_id', $requestId)->sole();
    expect($activity->command)->toBe('node:ssh-host-key:record');
    expect($activity->subject_id)->toBe($this->node->id);
    expect($activity->properties?->get('input'))->toBe($this->input);
    $publication = Operation::query()->sole();
    expect($publication->kind)->toBe('node.worker_known_hosts');
    expect($publication->node_id)->toBe(209);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-node-worker-known-hosts');
    expect($this->socket->sent[0]['payload']['inputs']['intent']['known_hosts_content'])->toContain($this->input['host'].' ssh-ed25519 '.$this->input['value']);
});

it('accepts an identical retry without replacing the stored identity', function (): void {
    $this->postJson($this->path, $this->input)->assertOk();
    $before = $this->node->fresh()->getAttributes();
    $this->postJson($this->path, $this->input)->assertOk();
    expect($this->node->fresh()->getAttributes())->toBe($before);
    expect(Operation::query()->count())->toBe(1);
    expect($this->socket->sent)->toHaveCount(1);
});

it('refuses replacement of a different complete or partial stored pin', function (bool $partial): void {
    $this->node->updateQuietly($partial ? ['ssh_host_fingerprint' => 'SHA256:prior']
        : ['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'prior', 'ssh_host_fingerprint' => 'SHA256:prior']);
    $before = $this->node->fresh()->getAttributes();
    $this->postJson($this->path, $this->input)->assertConflict()->assertJsonPath('error.code', 'node.ssh_host_key_already_recorded');
    expect($this->node->fresh()->getAttributes())->toBe($before);
})->with([false, true]);

it('refuses a scan from another address without mutation', function (): void {
    $this->postJson($this->path, [...$this->input, 'host' => '10.44.0.199'])->assertConflict()->assertJsonPath('error.code', 'node.ssh_host_key_target_mismatch');
    expect($this->node->fresh()->ssh_host_key)->toBeNull();
});

it('rejects malformed public keys and caller-supplied programs or fingerprint', function (array $change): void {
    $this->postJson($this->path, [...$this->input, ...$change])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($this->node->fresh()->ssh_host_key)->toBeNull();
})->with([
    [['value' => 'AAAA']], [['type' => 'ssh-rsa']], [['value' => 'not base64']],
    [['fingerprint' => 'SHA256:caller']], [['jump_host' => 14]], [['script' => 'scan']],
]);

it('requires normal directed Node access before recording a pin', function (bool $unknown): void {
    $this->withServerVariables(['REMOTE_ADDR' => $unknown ? '192.0.2.199' : FleetFixtures::node('pin-foreign')->wireguard_ip]);
    $this->postJson($this->path, $this->input)->assertForbidden();
    expect($this->node->fresh()->ssh_host_key)->toBeNull();
})->with([false, true]);
