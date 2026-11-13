<?php

declare(strict_types=1);

use App\Http\Requests\Instances\RegisterInstanceRequest;
use Illuminate\Validation\ValidationException;

it('refuses the removed include_worktrees field on registration', function (): void {
    $request = RegisterInstanceRequest::create('/api/v1/instances/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
        'source_path' => '/srv/orbit/apps/acme/main', 'project_id' => 4, 'include_worktrees' => false,
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $request->validationData())->toThrow(ValidationException::class);
});

it('accepts a registration body without include_worktrees', function (): void {
    $request = RegisterInstanceRequest::create('/api/v1/instances/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
        'source_path' => '/srv/orbit/apps/acme/main', 'project_id' => 4,
    ], JSON_THROW_ON_ERROR));

    expect($request->validationData())->toBe(['source_path' => '/srv/orbit/apps/acme/main', 'project_id' => 4]);
});
