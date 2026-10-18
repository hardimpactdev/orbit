<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionRegistry;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Project;
use App\Providers\ExtensionServiceProvider;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Tests\Support\TaskModelRouter;

function extensions_mcp(mixed $test, string $endpoint, string $method, array $params = []): TestResponse
{
    return $test->postJson($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

/** @return array<string, mixed> */
function extensions_mcp_message(TestResponse $response): array
{
    preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

    return json_decode((string) end($matches[1]), true);
}

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'extension-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.18',
        'wireguard_ip' => '10.44.0.18',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
});

it('disabled extension is invisible for tasks API and MCP tools', function (): void {
    $this->getJson('/api/v1/extensions')->assertOk()
        ->assertJsonPath('data.tasks', false)
        ->assertJsonPath('data.ai-router', false);
    $this->postJson('/api/v1/extensions/not-real/enable')->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    $this->getJson('/api/v1/tasks/status')->assertOk();
    $this->getJson('/api/v1/task-groups')->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');

    $tools = extensions_mcp($this, '/mcp', 'tools/list')->assertOk()->json('result.tools');
    expect(array_column($tools, 'name'))->not->toContain('tasks-list', 'tasks-create');
    $staleCall = extensions_mcp($this, '/mcp', 'tools/call', ['name' => 'tasks-list', 'arguments' => []])
        ->assertOk()->json('result');
    $staleError = json_decode($staleCall['content'][0]['text'], true);
    expect($staleCall['isError'])->toBeTrue()
        ->and($staleError['status'])->toBe(409)
        ->and($staleError['error']['code'])->toBe('extension.disabled');

    $search = extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'search_tools', 'arguments' => ['query' => 'tasks'],
    ])->assertOk()->json('result.content.0.text');
    expect($search)->not->toContain('tasks-list', 'tasks-create');
    $staleSearchExecution = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [['name' => 'tasks-list', 'arguments' => []]]],
    ]))['result'];
    expect($staleSearchExecution['isError'])->toBeTrue()
        ->and($staleSearchExecution['content'][0]['text'])->toContain('409', 'extension.disabled');

    $mixedBatch = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [
            ['name' => 'extension-list', 'arguments' => []],
            ['name' => 'tasks-list', 'arguments' => []],
        ]],
    ]))['result'];
    $mixedPayload = json_decode($mixedBatch['content'][0]['text'], true);
    expect($mixedBatch['isError'])->toBeTrue()
        ->and(array_column($mixedPayload['results'], 'name'))->toBe(['extension-list', 'tasks-list'])
        ->and($mixedPayload['results'][0]['isError'])->toBeFalse()
        ->and($mixedPayload['results'][1]['content'][0]['text'])->toContain('409', 'extension.disabled');

    $originalOutputLimit = config('mcp.tool_search.max_output_bytes');
    config()->set('mcp.tool_search.max_output_bytes', 300);
    $limitedBatch = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [
            ['name' => 'extension-list', 'arguments' => []],
            ['name' => 'tasks-list', 'arguments' => []],
        ]],
    ]))['result'];
    $limitedPayload = json_decode($limitedBatch['content'][0]['text'], true);
    expect($limitedBatch['isError'])->toBeTrue()
        ->and($limitedPayload['error']['kind'])->toBe('OutputLimitExceeded')
        ->and($limitedPayload['completedToolCalls'])->toBe(2)
        ->and($limitedPayload['attemptedToolCalls'])->toBe(2);
    config()->set('mcp.tool_search.max_output_bytes', $originalOutputLimit);

    $earlierErrorBatch = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [
            ['name' => 'not-a-tool', 'arguments' => []],
            ['name' => 'tasks-list', 'arguments' => []],
        ]],
    ]))['result'];
    $earlierErrorPayload = json_decode($earlierErrorBatch['content'][0]['text'], true);
    expect($earlierErrorBatch['isError'])->toBeTrue()
        ->and(array_column($earlierErrorPayload['results'], 'name'))->toBe(['not-a-tool'])
        ->and($earlierErrorPayload['results'][0]['content'][0]['text'])->toContain('not-a-tool');

    TaskModelRouter::ready();
    $this->postJson('/api/v1/extensions/tasks/enable')->assertOk()->assertJsonPath('data.enabled', true);
    $this->getJson('/api/v1/task-groups')->assertOk();
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->json('result.tools'), 'name'))->toContain('tasks-list');
    expect(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'search_tools', 'arguments' => ['query' => 'tasks list'],
    ])->json('result.content.0.text'))->toContain('tasks-list');
});

it('disabled extension is invisible for ai-router API and MCP tools', function (): void {
    $this->getJson('/api/v1/ai-router')->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->assertOk()->json('result.tools'), 'name'))
        ->not->toContain('ai-router-status', 'ai-router-provider-list');
    $staleCall = extensions_mcp($this, '/mcp', 'tools/call', ['name' => 'ai-router-status', 'arguments' => []])
        ->assertOk()->json('result');
    $staleError = json_decode($staleCall['content'][0]['text'], true);
    expect($staleCall['isError'])->toBeTrue()
        ->and($staleError['status'])->toBe(409)
        ->and($staleError['error']['code'])->toBe('extension.disabled');
    expect(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'search_tools', 'arguments' => ['query' => 'ai-router'],
    ])->assertOk()->json('result.content.0.text'))->not->toContain('ai-router-status');
    $staleSearchExecution = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [['name' => 'ai-router-status', 'arguments' => []]]],
    ]))['result'];
    expect($staleSearchExecution['isError'])->toBeTrue()
        ->and($staleSearchExecution['content'][0]['text'])->toContain('409', 'extension.disabled');

    $this->postJson('/api/v1/extensions/ai-router/enable')->assertOk();
    $this->getJson('/api/v1/ai-router')->assertOk();
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->json('result.tools'), 'name'))->toContain('ai-router-status');
    expect(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'search_tools', 'arguments' => ['query' => 'ai-router status'],
    ])->json('result.content.0.text'))->toContain('ai-router-status');
});

it('keeps tasks behind the ai-router extension, which holds its model route', function (): void {
    $this->postJson('/api/v1/extensions/tasks/enable')->assertStatus(409)
        ->assertJsonPath('error.code', 'extension.dependency_disabled')
        ->assertJsonPath('error.details', ['extension' => 'tasks', 'dependency' => 'ai-router']);
    $this->getJson('/api/v1/extensions')->assertJsonPath('data.tasks', false);

    TaskModelRouter::ready();
    $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
    $this->postJson('/api/v1/extensions/ai-router/disable')->assertStatus(409)
        ->assertJsonPath('error.code', 'extension.required')
        ->assertJsonPath('error.details', ['extension' => 'ai-router', 'required_by' => 'tasks']);
    $this->getJson('/api/v1/extensions')->assertJsonPath('data.ai-router', true);

    $this->postJson('/api/v1/extensions/tasks/disable')->assertOk();
    $this->postJson('/api/v1/extensions/ai-router/disable')->assertOk();
});

it('refuses to enable tasks while ai-router holds no management key, so no task VM stores a key it cannot revoke', function (): void {
    $this->postJson('/api/v1/extensions/ai-router/enable')->assertOk();
    $this->postJson('/api/v1/extensions/tasks/enable')->assertStatus(409)
        ->assertJsonPath('error.code', 'ai_router.disabled');
    $this->getJson('/api/v1/extensions')->assertJsonPath('data.tasks', false);

    TaskModelRouter::ready();
    $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
    $this->postJson('/api/v1/extensions/tasks/disable')->assertOk();
    $this->deleteJson('/api/v1/ai-router')->assertOk();

    expect(app(AiRouterState::class)->cliproxyManagementKey())->toBeNull();
    $this->postJson('/api/v1/extensions/tasks/enable')->assertStatus(409)
        ->assertJsonPath('error.code', 'ai_router.disabled');
    $this->getJson('/api/v1/extensions')->assertJsonPath('data.tasks', false);
});

final class DependentTestExtension extends ExtensionServiceProvider
{
    /** @var list<string> */
    public static array $hooks = [];

    public function name(): string
    {
        return 'dependent';
    }

    public function description(): string
    {
        return 'A test extension that needs documents.';
    }

    public function dependencies(): array
    {
        return ['documents'];
    }

    public function enable(): void
    {
        self::$hooks[] = 'enable';
    }

    public function disable(): void
    {
        self::$hooks[] = 'disable';
    }
}

final class FailingTestExtension extends ExtensionServiceProvider
{
    public static bool $switchSeenByHook = true;

    public function name(): string
    {
        return 'failing';
    }

    public function description(): string
    {
        return 'A test extension whose setup fails.';
    }

    public function enable(): void
    {
        self::$switchSeenByHook = app(ExtensionStore::class)->enabled('failing');

        throw new ResourceOperationException('failing.setup_failed', 'The failing extension could not be set up.', 409, details: ['step' => 'setup']);
    }
}

it('lists the extensions from the registered extension providers', function (): void {
    expect(array_keys($this->getJson('/api/v1/extensions')->assertOk()->json('data')))->toBe(['tasks', 'ai-router', 'documents', 'vault', 'conn'])
        ->and(app(ExtensionRegistry::class)->names())->toBe(['tasks', 'ai-router', 'documents', 'vault', 'conn']);

    $this->app->register(new DependentTestExtension($this->app));

    expect(array_keys($this->getJson('/api/v1/extensions')->assertOk()->json('data')))->toBe(['tasks', 'ai-router', 'documents', 'vault', 'conn', 'dependent']);
});

it('disabled extension is invisible for documents API and MCP tools', function (): void {
    $this->getJson('/api/v1/project-document-storage')->assertStatus(409)
        ->assertJsonPath('error.code', 'extension.disabled')
        ->assertJsonPath('error.message', 'The documents extension is disabled.');
    $project = Project::query()->create(['name' => 'Notes', 'slug' => 'notes', 'repository_url' => 'https://example.test/notes.git']);
    $this->getJson("/api/v1/projects/{$project->id}/documents")->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->assertOk()->json('result.tools'), 'name'))
        ->not->toContain('project-document-list', 'project-document-storage-show');
    $staleCall = extensions_mcp($this, '/mcp', 'tools/call', ['name' => 'project-document-list', 'arguments' => ['project' => 1]])
        ->assertOk()->json('result');
    expect($staleCall['isError'])->toBeTrue()
        ->and(json_decode($staleCall['content'][0]['text'], true)['error']['code'])->toBe('extension.disabled');

    $this->postJson('/api/v1/extensions/documents/enable')->assertOk()->assertJsonPath('data.enabled', true);
    $this->getJson('/api/v1/project-document-storage')->assertOk();
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->json('result.tools'), 'name'))->toContain('project-document-list');
});

it('disabled extension is invisible for vault and conn API and MCP tools', function (string $extension, string $path, string $tool): void {
    $this->getJson($path)->assertStatus(409)
        ->assertJsonPath('error.code', 'extension.disabled')
        ->assertJsonPath('error.message', "The {$extension} extension is disabled.");
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->assertOk()->json('result.tools'), 'name'))->not->toContain($tool);

    $this->postJson("/api/v1/extensions/{$extension}/enable")->assertOk()->assertJsonPath('data.enabled', true);
    $this->getJson($path)->assertOk();
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->json('result.tools'), 'name'))->toContain($tool);
})->with([
    'vault' => ['vault', '/api/v1/vault', 'vault-status'],
    'conn' => ['conn', '/api/v1/conn/profiles', 'conn-profile-list'],
]);

it('enables an extension only after its dependencies and runs its hooks after the switch changes', function (): void {
    DependentTestExtension::$hooks = [];
    $this->app->register(new DependentTestExtension($this->app));

    $this->postJson('/api/v1/extensions/dependent/enable')->assertStatus(409)
        ->assertJsonPath('error.code', 'extension.dependency_disabled')
        ->assertJsonPath('error.details.dependency', 'documents');
    expect(app(ExtensionStore::class)->enabled('dependent'))->toBeFalse()
        ->and(DependentTestExtension::$hooks)->toBe([]);

    $this->postJson('/api/v1/extensions/documents/enable')->assertOk();
    $this->postJson('/api/v1/extensions/dependent/enable')->assertOk()->assertJsonPath('data.enabled', true);
    $this->postJson('/api/v1/extensions/dependent/enable')->assertOk();
    expect(DependentTestExtension::$hooks)->toBe(['enable', 'enable']);

    $this->postJson('/api/v1/extensions/documents/disable')->assertStatus(409)
        ->assertJsonPath('error.code', 'extension.required')
        ->assertJsonPath('error.details.required_by', 'dependent');
    expect(app(ExtensionStore::class)->enabled('documents'))->toBeTrue();

    $this->postJson('/api/v1/extensions/dependent/disable')->assertOk()->assertJsonPath('data.enabled', false);
    $this->postJson('/api/v1/extensions/documents/disable')->assertOk();
    expect(DependentTestExtension::$hooks)->toBe(['enable', 'enable', 'disable'])
        ->and(app(ExtensionStore::class)->all())->toBe(['tasks' => false, 'ai-router' => false, 'documents' => false, 'vault' => false, 'conn' => false, 'dependent' => false]);
});

it('leaves the switch off and returns the hook error when an enable hook fails', function (): void {
    $this->app->register(new FailingTestExtension($this->app));

    $this->postJson('/api/v1/extensions/failing/enable')->assertStatus(409)
        ->assertJsonPath('error.code', 'failing.setup_failed')
        ->assertJsonPath('error.message', 'The failing extension could not be set up.')
        ->assertJsonPath('error.details.step', 'setup');

    expect(FailingTestExtension::$switchSeenByHook)->toBeFalse()
        ->and(app(ExtensionStore::class)->enabled('failing'))->toBeFalse();
    $this->getJson('/api/v1/extensions')->assertOk()->assertJsonPath('data.failing', false);
});

it('keeps an enabled extension enabled when its enable hook fails again', function (): void {
    $this->app->register(new FailingTestExtension($this->app));
    app(ExtensionStore::class)->set('failing', true);

    $this->postJson('/api/v1/extensions/failing/enable')->assertStatus(409)
        ->assertJsonPath('error.code', 'failing.setup_failed');

    expect(app(ExtensionStore::class)->enabled('failing'))->toBeTrue();
});
