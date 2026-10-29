<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Instances\InstanceLifecycleGitSecret;
use App\Domain\Instances\InstanceLifecycleUrlSecret;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Secret proof', 'slug' => 'secret-proof',
        'repository_url' => 'https://github.com/example/secret-proof.git', 'source_access' => 'gh_cli',
        'root' => 'public', 'default_branch' => 'main']);
    $this->node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.9']);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/secret-proof/proof', 'source_layout' => 'checkout', 'status' => 'reserved']);
    $this->operation = new Operation(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'input' => ['instance_id' => $this->instance->id, 'project_id' => $this->project->id,
            'repository' => $this->project->repository_url, 'repository_identity' => $this->project->repository_identity]]);
});

it('hydrates Git only in memory while retaining the credential-marked transport type', function (): void {
    $token = 'ghp_lifecycle_private_value_892374982374982374';
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldReceive('token')->once()->andReturn($token);
    app()->instance(GitHubCliToken::class, $cli);
    $reference = ['git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $this->project->id]];
    $result = app(OperationSecrets::class)->hydrate($this->operation, 'source-prepare', $reference, ['git_read' => 'instance.lifecycle_git']);
    expect($result['input']['git_read'])->toBeInstanceOf(GitReadEnvironment::class)
        ->and($result['input']['git_read']->githubToken())->toBe($token)
        ->and(json_encode($this->operation->toArray()))->not->toContain($token)
        ->and(app(OperationSecrets::class)->redact(['error_code' => 'instance.failed', 'echo' => $token], $result['values']))
        ->toBe(['error_code' => 'instance.failed', 'echo' => '[REDACTED]']);
});

it('hydrates public repository access without inventing credentials or redaction masks', function (): void {
    $repository = 'https://git.example.test/secret-proof.git';
    $this->project->forceFill(['repository_url' => $repository, 'repository_identity' => GitRepositoryIdentity::derive($repository), 'source_access' => 'github_app'])->save();
    $this->operation->input = [...$this->operation->input, 'repository' => $repository,
        'repository_identity' => $this->project->refresh()->repository_identity];
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldNotReceive('token');
    app()->instance(GitHubCliToken::class, $cli);
    $reference = ['git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $this->project->id]];

    $result = app(OperationSecrets::class)->hydrate($this->operation, 'source-prepare', $reference, ['git_read' => 'instance.lifecycle_git']);

    expect($result['input']['git_read'])->toBeInstanceOf(GitReadEnvironment::class)
        ->and($result['input']['git_read']->githubToken())->toBeNull()
        ->and($result['values'])->toBe([])
        ->and($this->operation->input)->not->toHaveKey('git_read');
});

it('hydrates task workspace Git access as the native empty credential type', function (): void {
    $this->operation->input = [...$this->operation->input, 'task_vm' => true];
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldNotReceive('token');
    app()->instance(GitHubCliToken::class, $cli);
    $reference = ['git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $this->project->id]];

    $result = app(OperationSecrets::class)->hydrate($this->operation, 'source-prepare', $reference, ['git_read' => 'instance.lifecycle_git']);

    expect($result['input']['git_read'])->toBeInstanceOf(GitReadEnvironment::class)
        ->and($result['input']['git_read']->githubToken())->toBeNull()
        ->and($result['values'])->toBe([]);
});

it('rejects a Project that does not own the target before resolving Git credentials', function (): void {
    $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://github.com/example/other.git']);
    $this->operation->input = [...$this->operation->input, 'project_id' => $other->id];
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldNotReceive('token');
    app()->instance(GitHubCliToken::class, $cli);
    expect(app(InstanceLifecycleGitSecret::class)->ownerId($this->operation))->toBeNull();
    expect(fn () => app(OperationSecrets::class)->hydrate($this->operation, 'source-prepare',
        ['git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $other->id]], ['git_read' => 'instance.lifecycle_git']))
        ->toThrow(ResourceOperationException::class);
});

it('refuses a repository URL outside the owning Project before resolving Git credentials', function (): void {
    $this->operation->input = [...$this->operation->input, 'repository' => 'https://github.com/foreign/private.git'];
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldNotReceive('token');
    app()->instance(GitHubCliToken::class, $cli);

    expect(fn () => app(OperationSecrets::class)->hydrate($this->operation, 'source-prepare',
        ['git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $this->project->id]], ['git_read' => 'instance.lifecycle_git']))
        ->toThrow(ResourceOperationException::class);
});

it('resolves stored Laravel lines without exposing them in the source value wrapper', function (): void {
    $key = 'base64:lifecycle-private-application-key';
    InstanceEnvironmentValue::query()->create(['instance_id' => $this->instance->id, 'env_key' => 'APP_KEY', 'env_value' => $key]);
    $value = app(InstanceLifecycleUrlSecret::class)->resolve($this->operation, $this->instance->id, 'source-configuration', 'app_key');
    expect($value->value)->toContain($key)->and($value->strings())->toContain($key)
        ->and(json_encode($value))->toBe('"[REDACTED]"')->and(print_r($value, true))->not->toContain($key);
});

it('retains a generated APP_KEY so hydration retries send the same value', function (): void {
    $value = app(InstanceLifecycleUrlSecret::class)->resolve($this->operation, $this->instance->id, 'source-configuration', 'app_key');
    $retry = app(InstanceLifecycleUrlSecret::class)->resolve($this->operation, $this->instance->id, 'source-configuration', 'app_key');
    expect($value->value)->toStartWith('APP_KEY="base64:')
        ->and($retry->value)->toBe($value->value);
    $stored = InstanceEnvironmentValue::query()->where('instance_id', $this->instance->id)->sole();
    expect($stored->env_key)->toBe('APP_KEY')->and($value->value)->toBe('APP_KEY="'.$stored->env_value.'"');
});

it('redacts raw and escaped APP_NAME values from protected receipts', function (): void {
    $name = "Proof \"quoted\" \$build\nback\\slash";
    $this->instance->environmentValues()->create(['env_key' => 'APP_NAME', 'env_value' => $name]);
    $hydrated = app(OperationSecrets::class)->hydrate($this->operation, 'source-configuration',
        ['app_name' => ['$secret' => 'instance.lifecycle_url', 'id' => $this->instance->id]], ['app_name' => 'instance.lifecycle_url']);

    expect(app(OperationSecrets::class)->redact(['raw' => $name, 'escaped' => 'Proof \\"quoted\\" \\$build\\nback\\\\slash'], $hydrated['values']))
        ->toBe(['raw' => '[REDACTED]', 'escaped' => '[REDACTED]']);
});

it('refuses a registration secret for a member outside the admitted set', function (): void {
    $this->operation->kind = 'instance.register';
    $this->operation->input = [...$this->operation->input, 'members' => [['instance_id' => $this->instance->id]]];
    expect(fn () => app(InstanceLifecycleUrlSecret::class)->resolve($this->operation, $this->instance->id, 'registration-999-configuration', 'app_key'))
        ->toThrow(ResourceOperationException::class);
});

it('refuses the same numeric owner on an unsupported target resource before minting credentials', function (): void {
    $this->operation->target_type = 'project';
    $this->operation->target_id = $this->project->id;
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldNotReceive('token');
    app()->instance(GitHubCliToken::class, $cli);
    expect(fn () => app(OperationSecrets::class)->hydrate($this->operation, 'source-prepare',
        ['git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $this->project->id]], ['git_read' => 'instance.lifecycle_git']))
        ->toThrow(ResourceOperationException::class);
});
