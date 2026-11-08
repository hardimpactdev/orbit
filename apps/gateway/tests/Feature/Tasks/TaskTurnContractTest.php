<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Http\Requests\Tasks\StartTaskTurnRequest;
use Illuminate\Validation\ValidationException;

it('rejects the removed analysis input on task turns', function (): void {
    $request = StartTaskTurnRequest::create('/api/v1/tasks/1/turn', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
        'subtask' => 2, 'role' => 'implementer', 'prompt' => 'Implement the brief.', 'thread' => 'new',
        'analysis' => ['operation' => 3, 'plan_sha256' => str_repeat('a', 64)],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $request->validationData())->toThrow(ValidationException::class);
});

it('has no task analysis admission route or operation kind', function (): void {
    expect(array_keys(OperationKinds::definitions()))->not->toContain('tasks.analyze')
        ->and(app('router')->getRoutes()->getByName('task:analyze'))->toBeNull();
});
