<?php

declare(strict_types=1);

use App\Domain\Instances\ContainerServer;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceImage;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

/* The container server a Project chooses (docs/reference/development-containers.md#container-server). */

describe('the container server setting', function (): void {
    beforeEach(function (): void {
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
        ]));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $this->project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
    });

    it('is conn for a Project that chose none, and the reply names it', function (): void {
        $this->getJson('/api/v1/projects/'.$this->project->id)->assertOk()->assertJsonPath('data.container_server', 'conn');
    });

    it('refuses conn while the recipe pins no Conn Server, and changes nothing', function (): void {
        $this->project->update(['container_server' => ContainerServer::T3]);
        containerServerConnUnpinned();

        $this->patchJson('/api/v1/projects/'.$this->project->id, ['container_server' => 'conn'])
            ->assertConflict()->assertJsonPath('error.code', 'project.container_server_unavailable');

        expect($this->project->refresh()->container_server)->toBe(ContainerServer::T3);
    });

    it('stores conn, which the recipe pins, and t3 again at any time', function (): void {
        $this->patchJson('/api/v1/projects/'.$this->project->id, ['container_server' => 'conn'])->assertOk()->assertJsonPath('data.container_server', 'conn');
        expect($this->project->refresh()->container_server)->toBe(ContainerServer::Conn);

        $this->patchJson('/api/v1/projects/'.$this->project->id, ['container_server' => 't3'])->assertOk()->assertJsonPath('data.container_server', 't3');
    });

    it('refuses a value that is no server', function (mixed $value): void {
        $this->patchJson('/api/v1/projects/'.$this->project->id, ['container_server' => $value])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['container_server']]]);
    })->with(['an unknown name' => 'pi', 'null' => null, 'the program name' => 'conn-server']);
});

describe('the programs of the container servers', function (): void {
    it('names each program in the agent folder, and the image\'s unit starts T3 as before', function (): void {
        expect(ContainerServer::T3->program())->toBe('/opt/orbit-agent/current/agent/bin/t3')
            ->and(ContainerServer::Conn->program())->toBe('/opt/orbit-agent/current/agent/bin/conn-server')
            ->and(InstanceImage::t3Unit())->toContain("\nExecStart=/opt/orbit-agent/current/agent/bin/t3 serve --host=0.0.0.0 --port=3773 --no-browser\n");
    });

    it('refuses a server whose program no part of the recipe\'s agent folder names', function (): void {
        ContainerServer::T3->assertPinned(app(InstanceContainer::class)->folders());
        ContainerServer::Conn->assertPinned(app(InstanceContainer::class)->folders());

        containerServerConnUnpinned();
        $folders = app(InstanceContainer::class)->folders();

        ContainerServer::T3->assertPinned($folders);
        expect(fn () => ContainerServer::Conn->assertPinned($folders))
            ->toThrow(ResourceOperationException::class, 'The agent folder has no conn-server program: the recipe pins no release of it.');
    });
});
