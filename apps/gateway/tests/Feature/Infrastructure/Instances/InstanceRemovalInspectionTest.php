<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

it('refuses invalid stored placement or incomplete active identity before dispatch', function (array $change): void {
    [$removal, $agents, $instance] = sourceInspectionFixture();
    $instance->update($change);

    expect(fn () => $removal->inspect($instance, true))->toThrow(RuntimeConvergenceException::class);
    expect($agents->calls)->toBe([]);
})->with([
    'outside the Instance directory' => [['checkout_path' => '/srv/unrelated']],
    'missing starting commit' => [['starting_commit' => null]],
]);

it('compares canonical repository identities in inspection evidence', function (string $origin, bool $equivalent): void {
    [$removal, $agents, $instance] = sourceInspectionFixture();
    $agents->answer('instance.inspect-source', static function (array $input) use ($origin): array {
        $row = sourceInspectionEvidence($input['checkout'], false);
        $row['origin'] = base64_encode($origin);

        return $row;
    });

    if ($equivalent) {
        expect($removal->inspect($instance, true)->repositoryIdentity)->toBe('example.test/site');
    } else {
        expect(fn () => $removal->inspect($instance, true))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        });
    }
    expect($agents->commands())->toBe(['instance.inspect-source']);
})->with([
    'equivalent protocol' => ['ssh://git@example.test/site.git', true],
    'different repository' => ['https://example.test/other.git', false],
    'trailing linefeed' => ["https://example.test/site.git\n", false],
]);

it('refuses dirty or unpublished inspection evidence unless force is explicit', function (bool $dirty): void {
    [$removal, $agents, $instance] = sourceInspectionFixture();
    $agents->answer('instance.inspect-source', static function (array $input) use ($dirty): array {
        $row = sourceInspectionEvidence($input['checkout'], false);
        $row['dirty'] = base64_encode($dirty ? '1' : '0');

        return $row;
    })->answer('instance.check-publication', ['published' => false]);

    expect(fn () => $removal->inspect($instance, false))->toThrow(RuntimeConvergenceException::class);
    expect($agents->commands())->not->toContain('instance.remove-source');
    $agents->calls = [];
    expect($removal->inspect($instance, true)->checkoutPath)->toBe($instance->checkout_path);
    expect($agents->commands())->toBe(['instance.inspect-source']);
})->with([false, true]);

it('snapshots the ordered teardown digest for fresh and resumed removal without a step-list admission dependency', function (bool $resumed, bool $empty): void {
    [$source, $agents, $instance] = sourceInspectionFixture();
    $instance->project->update(['type' => 'monorepo']);
    app()->instance(DevelopmentInstanceSourceRemoval::class, $source);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    if (! $empty) {
        foreach ([['second', 'echo second', 30, 1], ['first', 'echo first', 20, 0]] as [$name, $command, $timeout, $position]) {
            ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown',
                'name' => $name, 'command' => $command, 'timeout_seconds' => $timeout, 'position' => $position]);
        }
        ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'setup',
            'name' => 'setup-only', 'command' => 'echo setup', 'timeout_seconds' => 10, 'position' => 0]);
    }
    if ($resumed) {
        $removal = InstanceRemoval::query()->create(['id' => (string) Str::uuid(), 'requested_instance_id' => $instance->id,
            'requested_name' => $instance->name, 'force' => true, 'inventory_digest' => str_repeat('a', 64), 'total' => 1,
            'status' => 'removing', 'current_step' => 'source_preparation']);
        $removal->members()->create(['instance_id' => $instance->id, 'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
            'position' => 0, 'name' => $instance->name, 'environment' => 'development', 'source_layout' => 'checkout',
            'checkout_path' => $instance->checkout_path, 'source_digest' => str_repeat('a', 64), 'runtime_published' => true,
            'repository_identity' => $instance->project->repository_identity, 'root' => 'public', 'branch' => 'dev',
            'starting_commit' => str_repeat('a', 40), 'source_commit' => str_repeat('a', 40),
            'common_repository_path' => $instance->checkout_path, 'source_identity' => '12:34',
            'linked_worktree_paths' => [$instance->checkout_path]]);
        $instance->update(['status' => InstanceState::Removing]);
    }

    $snapshot = app(StartInstanceDestroyAction::class)->snapshot($instance, true);

    $expected = $empty ? '[]' : '[{"name":"first","command":"echo first","timeoutSeconds":20},{"name":"second","command":"echo second","timeoutSeconds":30}]';
    expect($snapshot['members'][0]['teardown_digest'])->toBe(hash('sha256', $expected));
    expect($snapshot['members'][0]['run_teardown'])->toBe(! $resumed);
    expect($agents->commands())->toBe($resumed ? [] : ['instance.inspect-source']);
})->with(['fresh' => [false], 'resumed' => [true]])->with(['empty list' => [true], 'ordered list' => [false]]);

/** @return array{RemoteDevelopmentInstanceSourceRemoval, FakeAgentCommandRunner, Instance, SourceInspectionLock} */
function sourceInspectionFixture(bool $worktree = false): array
{
    $node = Node::query()->create([
        'name' => 'app-dev', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10', 'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, production: false);
    $project = Project::query()->create([
        'name' => 'Site', 'slug' => 'site', 'repository_url' => 'https://example.test/site.git', 'default_branch' => 'main', 'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'node_id' => $node->id, 'project_id' => $project->id, 'name' => 'dev',
        'checkout_path' => '/srv/orbit/apps/site/dev', 'source_layout' => $worktree ? 'worktree' : 'checkout',
        'branch' => 'dev', 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::Active,
    ]);
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };
    $lock = new SourceInspectionLock;
    $agents = new FakeAgentCommandRunner;
    $agents->answer('instance.inspect-source', static function (array $input, Node $node) use ($lock, $worktree): array {
        expect($lock->held)->toBe($node->id);

        return sourceInspectionEvidence($input['checkout'], $worktree);
    })->answer('instance.check-publication', ['published' => true]);
    $removal = new RemoteDevelopmentInstanceSourceRemoval(
        $agents, $accounts, new CheckoutRemovalBoundary(new ProtectedPathCatalog), $lock, app(RepositoryReadAccess::class),
    );

    return [$removal, $agents, $instance, $lock];
}
final class SourceInspectionLock implements AppDevSourceOperationLock
{
    public ?int $held = null;

    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        $this->held = $nodeId;
        try {
            return $operation();
        } finally {
            $this->held = null;
        }
    }
}

function sourceInspectionEvidence(string $physical, bool $worktree): array
{
    $commit = str_repeat('a', 40);
    $inventory = "worktree {$physical}\0HEAD {$commit}\0branch refs/heads/dev\0\0";
    if ($worktree) {
        $inventory .= "worktree /srv/orbit/apps/site/default\0HEAD {$commit}\0branch refs/heads/main\0\0";
    }

    return array_map(base64_encode(...), [
        'top' => $physical, 'common' => $worktree ? '/srv/orbit/apps/site/default/.git' : $physical.'/.git',
        'origin' => 'https://example.test/site.git', 'branch' => 'dev', 'commit' => $commit,
        'dirty' => '0', 'source_identity' => '12:34', 'worktrees' => $inventory,
    ]);
}
