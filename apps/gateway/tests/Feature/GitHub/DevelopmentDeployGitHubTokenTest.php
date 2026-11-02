<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\DevelopmentDeployInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['swamp.run_token' => 'synthetic-run-token']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => [$request['type'] === 'vault.put' ? ['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
        'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]
        : ['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-deploy-direct', 'runId' => 'c189a69f-4069-4ca7-a158-f090ac995d3f']]];
    app()->instance(WebSocketClient::class, $this->socket);
    $this->instance = dev935_instance('native-dev-mint');
    $this->instance->project->update(['repository_url' => 'https://github.com/example/owned.git']);
    GitHubTestSupport::storeApp();
    $this->start = fn (): Operation => app(StartInstanceDeployAction::class)->execute($this->instance->fresh(), 'operator', null);
    $this->reply = ['token' => 'development-ephemeral-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
        'permissions' => ['contents' => 'read', 'metadata' => 'read']];
});

afterEach(function (): void {
    expect(json_encode([Operation::query()->get()->toArray(), Activity::query()->get()->toArray()], JSON_THROW_ON_ERROR))
        ->not->toContain('development-ephemeral-sentinel');
});

it('mints the owning repository Contents read at run start into the fetch credential copy only', function (): void {
    Http::fake(['*/repos/example/owned/installation' => Http::response(['id' => 7]),
        '*/access_tokens' => Http::response($this->reply, 201),
        '*/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'example/owned']]])]);
    $operation = ($this->start)();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['owned'], 'permissions' => ['contents' => 'read']]);
    $copy = collect($this->socket->sent)->firstWhere('payload.key', 'development-deploy-operation-'.$operation->id.'-credential');
    $run = collect($this->socket->sent)->firstWhere('type', 'workflow.run');
    expect($copy['payload']['value'])->toBe("https://x-access-token:development-ephemeral-sentinel@github.com\n")
        ->and($run['payload']['inputs']['execution_secrets']['credential']['key'])->toBe('development-deploy-operation-'.$operation->id.'-credential')
        ->and(json_encode($run, JSON_THROW_ON_ERROR))->not->toContain('development-ephemeral-sentinel');
});

it('fetches anonymously when the GitHub App has no installation on the repository', function (): void {
    Http::fake(['*/repos/example/owned/installation' => Http::response([], 404)]);
    $operation = ($this->start)();
    $copy = collect($this->socket->sent)->firstWhere('payload.key', 'development-deploy-operation-'.$operation->id.'-credential');
    expect($copy['payload']['value'])->toBe('');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens'));
});

it('refuses the run input after its admitted owner changed, before contacting GitHub', function (): void {
    Http::fake(['*/repos/example/owned/installation' => Http::response([], 404)]);
    $operation = ($this->start)();
    $this->instance->update(['checkout_path' => '/foreign/checkout']);
    Http::fake();
    expect(fn () => app(DevelopmentDeployInput::class)->workflowInput($operation->fresh()))->toThrow(ResourceOperationException::class);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens'));
});
