<?php

declare(strict_types=1);

use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ToolDefinition;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request without content reads php://input. Under PHP-FPM that is the MCP caller's JSON-RPC envelope, so a
 * bodiless internal request must carry empty content instead.
 */
it('gives a bodiless internal request empty content instead of the caller body', function (string $method): void {
    $kernel = new class implements Kernel
    {
        public ?Request $request = null;

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            $this->request = $request;

            return new Response('{}', 200);
        }

        public function terminate($request, $response): void {}

        public function getApplication(): never
        {
            throw new LogicException('Not used.');
        }
    };
    $definition = new ToolDefinition(
        name: 'tasks-subtask-destroy',
        title: 'Destroy a subtask',
        description: 'Deletes a subtask.',
        method: $method,
        path: '/api/v1/task-groups/{group}/tasks/{task}',
        pathInputs: ['group', 'task'],
        queryInputs: [],
        inputSchema: ['type' => 'object'],
    );

    new ApiDispatcher(app(), $kernel)->dispatch($definition, ['group' => 4, 'task' => 7], Request::create('/mcp', 'POST'));

    expect($kernel->request?->getPathInfo())->toBe('/api/v1/task-groups/4/tasks/7')
        ->and(new ReflectionProperty(SymfonyRequest::class, 'content')->getValue($kernel->request))->toBe('');
})->with(['DELETE', 'GET']);

it('keeps nested empty objects in the tool body and result', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->once()->withArgs(function (Request $request): bool {
        expect($request->getContent())->toBe('{"options":{},"flags":[]}');

        return true;
    })->andReturn(new Response('{"data":{"options":{},"flags":[]}}', 200));
    $definition = new ToolDefinition(
        name: 'example-create',
        title: 'Create an example',
        description: 'Preserve JSON types.',
        method: 'POST',
        path: '/api/v1/examples',
        pathInputs: [],
        queryInputs: [],
        inputSchema: ['type' => 'object'],
    );
    $caller = Request::create('/mcp', 'POST', content: '{"params":{"name":"example-create","arguments":{"options":{},"flags":[]}}}');

    $result = new ApiDispatcher(app(), $kernel)->dispatch($definition, ['options' => [], 'flags' => []], $caller);

    expect(json_encode($result->json(), JSON_THROW_ON_ERROR))->toBe('{"data":{"options":{},"flags":[]}}');
});
