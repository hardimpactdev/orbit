<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderHostKey;
use App\Domain\TaskVms\TaskVmUpCloudWorkflowInput;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

/** @return array<string,mixed> */
function upcloudCommandAllocation(TaskVm $vm): array
{
    return ['task_vm_id' => $vm->id, 'node_id' => 14, 'gateway_node_id' => 14, 'provider' => 'upcloud',
        'provider_id' => $vm->provider_id, 'name' => $vm->name, 'image_fingerprint' => $vm->image_fingerprint,
        'recipe_identity' => $vm->recipe_identity, 'upcloud' => ['zone' => 'nl-ams1', 'plan' => '2xCPU-4GB',
            'disk_gb' => 20, 'max_vms' => 4, 'source_template' => $vm->image_fingerprint, 'gateway_public_key' => 'ssh-ed25519 PUBLIC']];
}

function upcloudCommandVm(): TaskVm
{
    $vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'upcloud', 'state' => 'provisioning',
        'wireguard_ip' => '10.44.0.129', 'image_fingerprint' => '11111111-1111-4111-8111-111111111111', 'recipe_identity' => 'recipe-278']);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return $vm;
}

it('generates interoperable Ed25519 host intent without a production subprocess or scratch key file', function (): void {
    $pair = new TaskVmProviderHostKey()->generate('tvm-184');
    $directory = sys_get_temp_dir().'/upcloud-key-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        file_put_contents($directory.'/key', $pair['private']);
        chmod($directory.'/key', 0600);
        $process = proc_open(['/usr/bin/ssh-keygen', '-y', '-f', $directory.'/key'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('SSH key reader unavailable.');
        }
        $public = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0);
        expect(trim($public))->toBe($pair['public']);
        expect(new TaskVmProviderHostKey()->generate('tvm-184')['public'])->not->toBe($pair['public']);
    } finally {
        unlink($directory.'/key');
        rmdir($directory);
    }
});

it('records one encrypted bootstrap pair and reuses the exact shared descriptors', function (): void {
    config(['swamp.address' => '192.0.2.209', 'swamp.run_token' => 'synthetic-run-token', 'swamp.ca_path' => '/public/root.pem']);
    app()->instance(UpCloudApi::class, new UpCloudApi(TaskVmSettingsBuilder::make(), 'ucat_synthetic'));
    $writes = [];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function (array $request) use (&$writes): array {
        $writes[$request['payload']['key']] = $request['payload']['value'];

        return [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
            'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption',
        ]]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    $vm = upcloudCommandVm();
    $capture = app(TaskVmUpCloudWorkflowInput::class);
    $first = $capture->capture($vm, upcloudCommandAllocation($vm));
    $second = $capture->capture($vm, upcloudCommandAllocation($vm));
    $fresh = TaskVm::query()->findOrFail($vm->id);
    $pair = $fresh->provider_bootstrap ?? throw new LogicException('Missing captured host pair.');

    expect($second)->toBe($first);
    expect($socket->sent)->toHaveCount(2);
    expect($first['intent']['provider_host_public_key'])->toBe($pair['public']);
    expect($vm->ssh_host_key)->toBe($pair['public']);
    expect($fresh->ssh_host_key)->toBe($pair['public']);
    expect(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain($pair['private'], 'ucat_synthetic');
    expect(DB::table('task_vms')->where('id', $vm->id)->value('provider_bootstrap'))->not->toContain($pair['private']);
    expect(array_keys($writes))->toBe(['upcloud-api-gateway-14', 'task-vm-provider-bootstrap-'.$vm->id]);
    expect(json_decode($writes['task-vm-provider-bootstrap-'.$vm->id], true, flags: JSON_THROW_ON_ERROR))->toBe($pair);
    expect(DB::table('operations')->count())->toBe(0);
});

it('refuses a foreign executor before generating or copying a provider credential', function (): void {
    $vm = upcloudCommandVm();
    $allocation = upcloudCommandAllocation($vm);
    $allocation['node_id'] = 209;
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);

    expect(fn () => app(TaskVmUpCloudWorkflowInput::class)->capture($vm, $allocation))->toThrow(ResourceOperationException::class);
    expect(TaskVm::query()->findOrFail($vm->id)->provider_bootstrap)->toBeNull();
    expect($socket->sent)->toBeEmpty();
});

it('refuses an existing unpinned provider before generating a replacement host identity', function (): void {
    $vm = upcloudCommandVm();
    $vm->update(['provider_id' => '22222222-2222-4222-8222-222222222222']);

    expect(fn () => app(TaskVmUpCloudWorkflowInput::class)->capture($vm, upcloudCommandAllocation($vm)))->toThrow(ResourceOperationException::class);
    expect(TaskVm::query()->findOrFail($vm->id)->provider_bootstrap)->toBeNull();
});

it('rolls bootstrap intent back with outer admission when the shared vault copy refuses', function (): void {
    config(['swamp.address' => '192.0.2.209', 'swamp.run_token' => 'synthetic-run-token', 'swamp.ca_path' => '/public/root.pem']);
    app()->instance(UpCloudApi::class, new UpCloudApi(TaskVmSettingsBuilder::make(), 'ucat_synthetic'));
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (): never => throw new RuntimeException('Synthetic transport refusal');
    app()->instance(WebSocketClient::class, $socket);
    $vm = upcloudCommandVm();
    expect(fn () => DB::transaction(fn () => app(TaskVmUpCloudWorkflowInput::class)->capture($vm, upcloudCommandAllocation($vm))))
        ->toThrow(ResourceOperationException::class);
    $fresh = TaskVm::query()->findOrFail($vm->id);
    expect($fresh->provider_bootstrap)->toBeNull()->and($fresh->ssh_host_key)->toBeNull();
    expect($socket->sent)->toHaveCount(1);
    expect(DB::table('operations')->count())->toBe(0);
});
