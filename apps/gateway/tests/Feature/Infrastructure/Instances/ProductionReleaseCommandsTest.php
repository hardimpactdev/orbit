<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Shared\ResourceOperationException;
use Tests\Support\FakeAgentCommandRunner;

// The Gateway's part of the release commands: the input it sends and how it reads each answer. The agent's Rust tests
// in apps/agent/src/commands/production_tests.rs prove what each command does to a release home.

it('reads the selected release, or none, and a retained one by name', function (): void {
    [$deployment, $agents, $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer(
        'instance.inspect-release',
        static fn (array $input): array => ['release' => ($input['name'] ?? null) === null ? null : ['name' => $input['name'], 'commit' => str_repeat('a', 40)]],
    ));

    expect($deployment->selected($instance))->toBeNull()
        ->and($deployment->retained($instance, '20260101000000-a'))->toEqual(new DeploymentRelease('20260101000000-a', '/home/orbit-app-1/releases/20260101000000-a', str_repeat('a', 40)))
        ->and(array_column(array_column($agents->calls, 'input'), 'name'))->toBe(['20260101000000-a']);
});

it('rejects traversal before it asks the agent to inspect a release', function (): void {
    [$deployment, $agents, $instance] = release_commands();

    expect(fn () => $deployment->retained($instance, '../foreign'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('rollback.release_invalid'))
        ->and($agents->calls)->toBe([]);
});

it('lists the retained releases with the nullable selection', function (?string $selected): void {
    [$deployment, , $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instance.list-releases', [
        'selected' => $selected,
        'releases' => [['name' => 'initial', 'commit' => str_repeat('a', 40)], ['name' => '20260101000000-a', 'commit' => str_repeat('b', 40)]],
    ]));

    expect($deployment->releases($instance))->toEqual(new DeploymentReleaseState(['20260101000000-a', 'initial'], $selected));
})->with(['selected' => 'initial', 'none selected' => null]);

it('rejects a listing that repeats a release or selects one it does not list', function (array $answer): void {
    [$deployment, , $instance] = release_commands(fn (FakeAgentCommandRunner $agents) => $agents->answer('instance.list-releases', $answer));

    expect(fn () => $deployment->releases($instance))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('deployment.receipt_invalid'));
})->with([
    'repeated release' => [['selected' => null, 'releases' => [['name' => 'initial', 'commit' => str_repeat('a', 40)], ['name' => 'initial', 'commit' => str_repeat('a', 40)]]]],
    'unlisted selection' => [['selected' => '20260101000000-a', 'releases' => [['name' => 'initial', 'commit' => str_repeat('a', 40)]]]],
    'short commit' => [['selected' => null, 'releases' => [['name' => 'initial', 'commit' => 'abc']]]],
]);
