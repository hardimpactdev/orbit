<?php

declare(strict_types=1);

use App\Domain\Operations\OperationArtifact;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->host = Node::query()->create(['name' => 'build-host', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $this->host->accessibleNodes()->attach($this->gateway);
    $this->artifactHome = sys_get_temp_dir().'/orbit-artifact-'.Str::random(12);
    File::ensureDirectoryExists($this->artifactHome.'/sandbox-artifacts', 0700);
    $this->artifactPath = $this->artifactHome.'/sandbox-artifacts/pi-pinned';
    $this->bytes = 'pinned Pi artifact fixture';
    file_put_contents($this->artifactPath, $this->bytes);
    $this->digest = hash('sha256', $this->bytes);
    config(['orbit.home' => $this->artifactHome]);
    $this->build = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'recipe_identity' => 'sha256:'.str_repeat('a', 64), 'recipe_generation' => 1]);
    $this->run = '878a384f-8b8a-4066-bd17-dcad8eac3e86';
    $this->operation = Operation::query()->create(['kind' => 'task_vm.image_build', 'target_type' => 'task_vm_image', 'target_id' => $this->build->id,
        'node_id' => $this->host->id, 'status' => 'queued', 'sequence' => 1,
        'state' => ['task_vm_image_writer' => 'direct', 'swamp_run_id' => $this->run],
        'input' => ['build_id' => $this->build->id, 'attempt' => 1, 'pi_artifact' => ['path' => $this->artifactPath, 'sha256' => $this->digest],
            'pi_artifact_file' => ['$secret' => 'task_vm.image_artifact', 'id' => $this->build->id]]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/private-material?run_id='.$this->run;
    $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip]);
});

afterEach(function (): void {
    File::deleteDirectory($this->artifactHome);
});

it('returns a bounded reference and serves only its admitted digest through the same private read', function (): void {
    $before = $this->operation->fresh()->getAttributes();
    $response = $this->getJson($this->path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $reference = $response->json('data.pi_artifact_file');
    expect($reference)->toBe(['path' => $this->path.'&artifact=pi_artifact_file', 'sha256' => $this->digest,
        'bytes' => strlen($this->bytes), 'max_bytes' => OperationArtifact::MaximumBytes]);
    expect($response->getContent())->not->toContain($this->artifactPath)->not->toContain($this->bytes);
    $download = $this->get($reference['path'])->assertOk()->assertDownload('artifact-'.$this->digest)
        ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Artifact-Sha256', $this->digest)
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Length', (string) strlen($this->bytes));
    expect(file_get_contents($download->baseResponse->getFile()->getPathname()))->toBe($this->bytes);
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    expect(json_encode(Activity::query()->get()->toArray()))->not->toContain($this->bytes)->not->toContain($this->artifactPath);
});

it('refuses artifact reads from another Node even when that peer can access the Gateway', function (): void {
    $other = Node::query()->create(['name' => 'other-peer', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
    $other->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->get($this->path.'&artifact=pi_artifact_file')->assertForbidden();
});

it('refuses foreign unbound and terminal runs before opening an artifact', function (string $change): void {
    if ($change === 'foreign') {
        $this->path = str_replace($this->run, '878a384f-8b8a-4066-bd17-dcad8eac3e87', $this->path);
    } elseif ($change === 'unbound') {
        $this->operation->update(['state' => ['task_vm_image_writer' => 'direct']]);
    } else {
        $this->operation->update(['status' => $change]);
    }
    $this->get($this->path.'&artifact=pi_artifact_file')->assertConflict();
})->with(['foreign', 'unbound', 'succeeded', 'failed', 'cancelled']);

it('refuses undeclared artifact fields, foreign references and stale image attempts', function (string $change): void {
    $field = 'pi_artifact_file';
    if ($change === 'field') {
        $field = 'other_artifact';
    } elseif ($change === 'owner') {
        $this->operation->update(['input' => [...$this->operation->input, 'pi_artifact_file' => ['$secret' => 'task_vm.image_artifact', 'id' => $this->build->id + 1]]]);
    } else {
        $this->build->update(['attempt' => 2]);
    }
    $this->get($this->path.'&artifact='.$field)->assertUnprocessable();
})->with(['field', 'owner', 'attempt']);

it('refuses changed, symlinked, oversized and out-of-scope artifact files', function (string $change): void {
    if ($change === 'digest') {
        file_put_contents($this->artifactPath, 'changed bytes');
    } elseif ($change === 'symlink') {
        rename($this->artifactPath, $this->artifactPath.'-real');
        symlink($this->artifactPath.'-real', $this->artifactPath);
    } elseif ($change === 'parent-symlink') {
        rename($this->artifactHome.'/sandbox-artifacts', $this->artifactHome.'/real');
        symlink($this->artifactHome.'/real', $this->artifactHome.'/sandbox-artifacts');
    } elseif ($change === 'oversized') {
        $file = fopen($this->artifactPath, 'r+');
        ftruncate($file, OperationArtifact::MaximumBytes + 1);
        fclose($file);
    } else {
        $this->operation->update(['input' => [...$this->operation->input, 'pi_artifact' => ['path' => $this->artifactHome.'/sandbox-artifacts/../outside', 'sha256' => $this->digest]]]);
    }
    $this->get($this->path.'&artifact=pi_artifact_file')->assertConflict()->assertJsonPath('error.code', 'operation.artifact_unavailable');
})->with(['digest', 'symlink', 'parent-symlink', 'oversized', 'path']);
