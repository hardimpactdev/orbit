<?php

declare(strict_types=1);

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\ProductionRepositoryRecord;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Instances\RemoteProductionRepositoryBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

// The Gateway's part of a production re-bind: what it reads from the agent, which changes it sends in which order,
// and how deployment names a binding failure. The agent's Rust tests in apps/agent/src/commands/production_tests.rs
// re-bind a real home and prune it afterwards.

const REBIND_SSH_URL = 'git@github.com:acme/site.git';
const REBIND_HTTPS_URL = 'https://github.com/acme/site.git';

describe('ProductionRepositoryRecord', function (): void {
    it('treats SSH and HTTPS URLs of one repository as the same repository', function (): void {
        $record = new ProductionRepositoryRecord(REBIND_SSH_URL, REBIND_SSH_URL, ['a' => REBIND_SSH_URL, 'b' => REBIND_HTTPS_URL]);

        expect($record->sameRepositoryAs(REBIND_HTTPS_URL))->toBeTrue()
            ->and($record->sameRepositoryAs('https://github.com/acme/site'))->toBeTrue()
            ->and($record->sameRepositoryAs('https://github.com/acme/other.git'))->toBeFalse()
            ->and($record->boundTo(REBIND_HTTPS_URL))->toBeFalse()
            ->and($record->withRepository(REBIND_HTTPS_URL)->boundTo(REBIND_HTTPS_URL))->toBeTrue()
            ->and(new ProductionRepositoryRecord('not a url', null, [])->sameRepositoryAs(REBIND_HTTPS_URL))->toBeFalse();
    });

    it('round-trips through stored inventory', function (): void {
        $record = new ProductionRepositoryRecord(REBIND_SSH_URL, null, ['initial' => REBIND_SSH_URL]);

        expect(ProductionRepositoryRecord::fromArray($record->toArray()))->toEqual($record);
    });
});

describe('RemoteProductionRepositoryBinding', function (): void {
    it('reads the markers and every release origin, and leaves out a release without one', function (): void {
        [$instance, , $binding, $agents] = rebind_instance(REBIND_SSH_URL);
        $agents->answer('instance.inspect-repository-binding', [
            'markers' => ['release-layout' => rebind_marker(REBIND_SSH_URL, true), 'initial-clone' => rebind_marker(REBIND_SSH_URL, false)],
            'releases' => ['initial' => REBIND_SSH_URL, '20261001000000-a' => REBIND_SSH_URL, 'partial' => null],
        ]);

        expect($binding->inspect($instance))->toEqual(new ProductionRepositoryRecord(
            REBIND_SSH_URL,
            REBIND_SSH_URL,
            ['20261001000000-a' => REBIND_SSH_URL, 'initial' => REBIND_SSH_URL],
        ))->and($agents->calls[0]['input'])->toBe(['user' => 'rebind', 'home' => '/home/rebind', 'instance' => $instance->id]);
    });

    it('refuses a marker for another home', function (): void {
        [$instance, , $binding, $agents] = rebind_instance(REBIND_SSH_URL);
        $agents->answer('instance.inspect-repository-binding', [
            'markers' => ['release-layout' => base64_encode(REBIND_SSH_URL."\0someone\0/home/someone\0initial\0")],
            'releases' => [],
        ]);

        expect(fn () => $binding->inspect($instance))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('project.production_rebind_failed'));
    });

    it('moves each release, then the initial clone, and the release layout marker last', function (): void {
        [$instance, , $binding, $agents] = rebind_instance(REBIND_HTTPS_URL);
        $agents->answer('instance.rebind-repository', []);
        $record = new ProductionRepositoryRecord(REBIND_SSH_URL, REBIND_SSH_URL, ['20261001000000-a' => REBIND_SSH_URL, 'initial' => REBIND_SSH_URL]);

        $binding->rebind($instance, $record, $record->withRepository(REBIND_HTTPS_URL));
        $binding->rebind($instance, new ProductionRepositoryRecord(null, null, []), new ProductionRepositoryRecord(null, null, []));

        expect($agents->calls)->toHaveCount(1)
            ->and($agents->calls[0]['input']['changes'])->toBe([
                ['kind' => 'release', 'name' => '20261001000000-a', 'expected' => REBIND_SSH_URL, 'target' => REBIND_HTTPS_URL],
                ['kind' => 'release', 'name' => 'initial', 'expected' => REBIND_SSH_URL, 'target' => REBIND_HTTPS_URL],
                ['kind' => 'initial-clone', 'expected' => REBIND_SSH_URL, 'target' => REBIND_HTTPS_URL],
                ['kind' => 'release-layout', 'expected' => REBIND_SSH_URL, 'target' => REBIND_HTTPS_URL],
            ]);
    });

    it('names a refused re-bind as a failed re-bind', function (): void {
        [$instance, , $binding, $agents] = rebind_instance(REBIND_HTTPS_URL);
        $agents->refuse('instance.rebind-repository', 'instance.release_invalid', ['check' => 'the marker records the expected URL']);
        $record = new ProductionRepositoryRecord('git@github.com:acme/other.git', null, []);

        expect(fn () => $binding->rebind($instance, $record, $record->withRepository(REBIND_HTTPS_URL)))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('project.production_rebind_failed'));
    });
});

describe('a deployment on a home bound to another URL', function (): void {
    it('names the repair, or another repository, or keeps the original error', function (string $recorded, string $class, string $code): void {
        [$instance, $deployment, , $agents] = rebind_instance(REBIND_HTTPS_URL);
        $agents->refuse('instance.inspect-release', 'instance.release_invalid', ['check' => 'the layout marker names this home'])
            ->answer('instance.inspect-repository-binding', ['markers' => ['release-layout' => rebind_marker($recorded, true)], 'releases' => []]);

        try {
            $deployment->selected($instance);
            $this->fail('The binding failure was not reported.');
        } catch (ResourceOperationException|RuntimeConvergenceException $exception) {
            expect($exception)->toBeInstanceOf($class)
                ->and($exception->errorCode)->toBe($code);
        }
    })->with([
        'the SSH URL of the same repository' => [REBIND_SSH_URL, ResourceOperationException::class, 'deployment.repository_rebind_required'],
        'another repository' => ['git@github.com:acme/other.git', ResourceOperationException::class, 'deployment.repository_mismatch'],
        'the Project URL' => [REBIND_HTTPS_URL, RuntimeConvergenceException::class, 'deployment.selection_invalid'],
    ]);
});

function rebind_marker(string $repository, bool $layout): string
{
    return base64_encode($repository."\0rebind\0/home/rebind\0".($layout ? "initial\0" : ''));
}

/** @return array{Instance, RemoteProductionDeployment, RemoteProductionRepositoryBinding, FakeAgentCommandRunner} */
function rebind_instance(string $repository): array
{
    $node = Node::query()->create(['name' => 'rebind-node', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.231', 'wireguard_ip' => '10.44.0.231', 'user' => 'orbit']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Rebind', 'slug' => 'rebind', 'repository_url' => $repository, 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production', 'environment' => 'production',
        'source_layout' => 'release', 'checkout_path' => '/home/rebind/releases/initial', 'production_user' => 'rebind',
        'production_home' => '/home/rebind', 'root' => 'public', 'branch' => 'main', 'provisioning_step' => 'active', 'status' => 'active',
    ]);
    $agents = new FakeAgentCommandRunner;
    $binding = new RemoteProductionRepositoryBinding($agents);

    return [
        $instance,
        new RemoteProductionDeployment($agents, app(RepositoryReadAccess::class), app(PrepareVpEnvironmentAction::class), null, $binding),
        $binding,
        $agents,
    ];
}
