<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorDatabaseMaterialSource;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->root = Node::query()->create(['name' => 'doctor-root', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '192.0.2.8']);
    $this->worker = Node::query()->create(['name' => 'doctor-worker', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']);
    $this->ownServer = DatabaseServer::query()->create(['node_id' => $this->worker->id, 'slug' => 'doctor-owned', 'driver' => 'mysql', 'tag' => '8.0', 'port' => 3306, 'status' => 'active', 'root_password' => 'worker-private-sentinel']);
    $this->rootServer = DatabaseServer::query()->create(['node_id' => $this->root->id, 'slug' => 'doctor-root-owned', 'driver' => 'mysql', 'tag' => '8.0', 'port' => 3306, 'status' => 'active', 'root_password' => 'root-private-sentinel']);
    $this->source = app(DoctorDatabaseMaterialSource::class);
    $this->operation = new Operation(['kind' => 'doctor.inspect', 'target_type' => 'node', 'target_id' => $this->root->id, 'node_id' => $this->root->id, 'input' => [
        'participant_node_ids' => [$this->root->id, $this->worker->id],
        'private_bindings' => ['database_servers' => ['owner_node_id' => $this->root->id, 'read_node_ids' => [$this->root->id, $this->worker->id], 'write_node_ids' => []]],
        'nodes' => [
            ['id' => $this->root->id, 'resources' => ['database_servers' => [['id' => $this->rootServer->id]]]],
            ['id' => $this->worker->id, 'resources' => ['database_servers' => [['id' => $this->ownServer->id]]]],
        ],
    ]]);
    request()->setUserResolver(fn () => $this->worker);
});

it('returns only the authenticated admitted workers existing database credentials', function (): void {
    $material = $this->source->resolve($this->operation, $this->root->id, '', 'database_servers');
    expect($material->value)->toBe([$this->ownServer->id => ['password' => 'worker-private-sentinel', 'acl_users' => []]])
        ->and($material->strings())->toContain('worker-private-sentinel')->not->toContain('root-private-sentinel')
        ->and(json_encode($material))->toBe('"[REDACTED]"');
    request()->setUserResolver(fn () => $this->root);
    expect($this->source->resolve($this->operation, $this->root->id, '', 'database_servers')->value)
        ->toBe([$this->rootServer->id => ['password' => 'root-private-sentinel', 'acl_users' => []]]);
});

it('refuses a foreign server in a participants admitted inventory before reading its credential', function (): void {
    $input = $this->operation->input;
    $input['nodes'][1]['resources']['database_servers'] = [['id' => $this->rootServer->id]];
    $this->operation->input = $input;
    expect(fn () => $this->source->resolve($this->operation, $this->root->id, '', 'database_servers'))->toThrow(ResourceOperationException::class);
});

it('refuses missing authentication undeclared fields wrong owners and nonreaders', function (): void {
    request()->setUserResolver(fn () => null);
    expect(fn () => $this->source->resolve($this->operation, $this->root->id, '', 'database_servers'))->toThrow(ResourceOperationException::class);
    request()->setUserResolver(fn () => $this->worker);
    expect(fn () => $this->source->resolve($this->operation, $this->worker->id, '', 'database_servers'))->toThrow(ResourceOperationException::class);
    expect(fn () => $this->source->resolve($this->operation, $this->root->id, '', 'password'))->toThrow(ResourceOperationException::class);
    $input = $this->operation->input;
    $input['private_bindings']['database_servers']['read_node_ids'] = [$this->root->id];
    $this->operation->input = $input;
    expect(fn () => $this->source->resolve($this->operation, $this->root->id, '', 'database_servers'))->toThrow(ResourceOperationException::class);
});

it('refuses an authenticated Node missing from admitted participants or inventory', function (): void {
    $input = $this->operation->input;
    $input['participant_node_ids'] = [$this->root->id];
    $input['private_bindings']['database_servers']['read_node_ids'] = [$this->root->id];
    $this->operation->input = $input;
    expect(fn () => $this->source->resolve($this->operation, $this->root->id, '', 'database_servers'))->toThrow(ResourceOperationException::class);
    $input['participant_node_ids'][] = $this->worker->id;
    $input['private_bindings']['database_servers']['read_node_ids'][] = $this->worker->id;
    $input['nodes'] = [$input['nodes'][0]];
    $this->operation->input = $input;
    expect(fn () => $this->source->resolve($this->operation, $this->root->id, '', 'database_servers'))->toThrow(ResourceOperationException::class);
});
