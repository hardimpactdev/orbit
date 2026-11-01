<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitDockerMigrationAction;
use App\Domain\Fleet\DockerMigrationIntent;
use App\Domain\Fleet\DockerMigrationProjection;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->node = FleetFixtures::node('docker-owner');
    $this->node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIFixture']);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $m): array => ($m['type'] === 'vault.put') ? [['type' => 'vault.put', 'id' => $m['id'], 'payload' => ['data' => ['vaultName' => $m['payload']['vaultName'], 'secretKey' => $m['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $m['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-fleet-docker-migrate', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fake.token']);
});

it('requires explicit Docker maintenance consent before admission', function (bool $restore): void {
    expect(Artisan::call('node:migrate-docker', ['node' => $this->node->name, '--restore-policies' => $restore]))->toBe(1)
        ->and(Artisan::output())->toContain('--yes is required')->and(Operation::count())->toBe(0)->and($this->agents->calls)->toBe([]);
})->with([false, true]);

it('refuses conflicting recovery flags and changed recipes before admission', function (array $options): void {
    expect(Artisan::call('node:migrate-docker', ['node' => $this->node->name, '--yes' => true, ...$options]))->toBe(1)
        ->and(Operation::count())->toBe(0)->and($this->agents->calls)->toBe([]);
})->with([[['--restore-policies' => true, '--fallback' => true]], [['--restore-policies' => true, '--dry-run' => true]], [['--recipe' => 'sha256:other']]]);

it('admits one native root and exposes only safe Process identity', function (): void {
    $process = Process::query()->create(['owner_type' => $this->node->getMorphClass(), 'owner_id' => $this->node->id, 'name' => 'web', 'runtime' => 'docker', 'runtime_config' => ['image' => 'nginx:1', 'command' => ['/bin/server'], 'environment' => ['SECRET' => 'private-value']], 'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);
    $op = app(AdmitDockerMigrationAction::class)->execute($this->node, (new RecipeLoader)->load(), 'migrate');
    expect($op->kind)->toBe('fleet.docker_migrate')->and($op->state['swamp_run_id'])->toBe($this->run)
        ->and($this->socket->sent)->toHaveCount(2)->and($this->agents->calls)->toBe([])
        ->and(json_encode($op->input))->not->toContain('private-value', '/bin/server')
        ->and($op->input['migration']['process_ids'])->toBe([(string) $process->id]);
    $source = app(DockerMigrationIntent::class);
    $native = app(DockerMigrationProjection::class)->input($op);
    expect($native['intent']['processes'][0]['id'])->toBe($process->id)
        ->and($native['intent']['processes'][0]['intent']['installation']['definition']['command'])->toBe(['/bin/server'])
        ->and(json_encode($native))->not->toContain('private-value')
        ->and($native['execution_secrets']['member_'.$process->id]['key'])->toBe('process-parent-operation-'.$op->id.'-node-'.$this->node->id.'-member-'.$process->id)
        ->and($this->socket->sent[0]['payload']['value'])->toBe('{"SECRET":"private-value"}');
    $process->update(['desired_state' => 'stopped']);
    expect(fn () => $source->nativeInput($op))->toThrow(ResourceOperationException::class);
});

it('keeps foreign Node Processes out of admitted migration and denies foreign private ownership', function (): void {
    $other = FleetFixtures::node('docker-foreign');
    Process::query()->create(['owner_type' => $other->getMorphClass(), 'owner_id' => $other->id, 'name' => 'foreign', 'runtime' => 'docker', 'runtime_config' => ['image' => 'nginx:1', 'command' => ['/bin/server']], 'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);
    $op = app(AdmitDockerMigrationAction::class)->execute($this->node, (new RecipeLoader)->load(), 'plan');
    expect($op->input['migration']['process_ids'])->toBe([])
        ->and(app(DockerMigrationIntent::class)->nativeInput($op)['processes'])->toBe([]);
});

it('uses the normal Node access and confirmation boundary for async HTTP admission', function (): void {
    $gateway = FleetFixtures::node('docker-gateway', [RoleName::Gateway]);
    app(GatewayServingHost::class)->remember($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $path = '/api/v1/fleet/nodes/'.$this->node->id.'/docker-migration';
    $this->postJson($path, ['mode' => 'migrate'])->assertUnprocessable();
    $this->postJson($path, ['mode' => 'restore-policies', 'confirm' => true, 'fallback' => true])->assertUnprocessable();
    $this->postJson($path, ['mode' => 'plan'])->assertAccepted()->assertJsonPath('data.kind', 'fleet.docker_migrate');
    expect($this->socket->sent)->toHaveCount(1)->and($this->agents->calls)->toBe([]);
});

it('records a bounded native result without executing or re-validating host work', function (): void {
    $op = app(AdmitDockerMigrationAction::class)->execute($this->node, (new RecipeLoader)->load(), 'plan');
    $result = app(DockerMigrationProjection::class)->apply($op, ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['healthy' => true, 'planned' => true, 'private' => 'must-not-persist']]);
    expect($result->status)->toBe(OperationStatus::Succeeded)->and($result->result)->toBe(['healthy' => true, 'planned' => true])->and($this->agents->calls)->toBe([]);
});

it('denies a foreign peer before Docker admission', function (): void {
    $caller = FleetFixtures::node('docker-caller');
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->postJson('/api/v1/fleet/nodes/'.$this->node->id.'/docker-migration', ['mode' => 'migrate', 'confirm' => true])->assertForbidden();
    expect(Operation::count())->toBe(0)->and($this->socket->sent)->toBe([])->and($this->agents->calls)->toBe([]);
});

it('preserves empty public maps for the native input schema', function (): void {
    $op = app(AdmitDockerMigrationAction::class)->execute($this->node, (new RecipeLoader)->load(), 'plan');
    $wire = json_encode(app(DockerMigrationProjection::class)->input($op->refresh()), JSON_THROW_ON_ERROR);
    expect($wire)->toContain('"process_states":{}', '"process_specs":{}')->not->toContain('private-value');
});

it('does not expose Process environment through the retired private-material route', function (): void {
    $op = app(AdmitDockerMigrationAction::class)->execute($this->node, (new RecipeLoader)->load(), 'plan');
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$op->id.'/private-material?run_id='.$this->run)->assertUnprocessable();
    expect(app(DirectOperation::class)->definition('fleet.docker_migrate'))->not->toHaveKey('private_material');
});

it('holds one Docker migration admission per Node until terminal native projection', function (string $status): void {
    $action = app(AdmitDockerMigrationAction::class);
    $recipe = (new RecipeLoader)->load();
    $first = $action->execute($this->node, $recipe, 'migrate');
    expect($first->lock_key)->toBe('docker_migrate:node:'.$this->node->id);
    try {
        $action->execute($this->node, $recipe, 'plan');
        test()->fail('A second migration root must be refused.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('operation.target_busy');
    }
    expect(Operation::count())->toBe(1)->and($this->socket->sent)->toHaveCount(1);
    $other = FleetFixtures::node('another-docker-node');
    $other->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIFixture']);
    $independent = $action->execute($other, $recipe, 'plan');
    expect($independent->lock_key)->toBe('docker_migrate:node:'.$other->id);
    app(DockerMigrationProjection::class)->apply($first, ['run_id' => $this->run, 'status' => $status,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => []]);
    expect($first->fresh()->lock_key)->toBeNull()->and($independent->fresh()->lock_key)->not->toBeNull();
    $next = $action->execute($this->node, $recipe, 'plan');
    expect($next->id)->not->toBe($first->id)->and($next->lock_key)->toBe('docker_migrate:node:'.$this->node->id);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);
