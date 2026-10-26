<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WebSocket\NativeWebSocketRouteInspector;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

// The agent's Rust tests prove which routes collide and that a failed route list prints nothing.

function route_inspection_instance(array $attributes = []): Instance
{
    $node = new Node(['name' => 'prod', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4']);
    orbit_test_set_app_placement_role($node, true);
    $instance = new Instance([
        'production_user' => 'orbit-mealou', 'production_home' => '/home/orbit-mealou',
        'checkout_path' => '/home/orbit-mealou/releases/one', 'selected_php_version' => '8.5', 'source_is_laravel' => true,
        ...$attributes,
    ]);

    return $instance->setRelation('node', $node)->setRelation('project', new Project(['root' => 'public']));
}

it('asks the agent to read the current release routes as the production user', function (bool $collision): void {
    $agents = new FakeAgentCommandRunner()->answer('instance.inspect-laravel-routes', ['collision' => $collision]);

    expect(new NativeWebSocketRouteInspector($agents)->hasCollisions(route_inspection_instance()))->toBe($collision)
        ->and($agents->calls)->toBe([[
            'node' => 'prod',
            'command' => 'instance.inspect-laravel-routes',
            'input' => ['user' => 'orbit-mealou', 'home' => '/home/orbit-mealou', 'application' => '/home/orbit-mealou/current', 'php_version' => '8.5'],
        ]]);
})->with([true, false]);

it('fails without the agent message when the routes cannot be read', function (?string $refusal): void {
    $agents = $refusal === null
        ? new FakeAgentCommandRunner()->answerMalformed('instance.inspect-laravel-routes', [])
        : new FakeAgentCommandRunner()->refuse('instance.inspect-laravel-routes', $refusal);

    expect(fn () => new NativeWebSocketRouteInspector($agents)->hasCollisions(route_inspection_instance()))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('websocket.route_inspection_failed')
                ->and($exception->getMessage())->not->toContain('Refused');
        });
})->with([
    'unreadable routes' => ['instance.laravel_routes_unreadable'],
    'release outside the home' => ['instance.path_mismatch'],
    'answer without a collision' => [null],
]);

it('refuses an Instance without a valid production user, home, or PHP version before it asks the agent', function (array $attributes): void {
    $agents = new FakeAgentCommandRunner;

    expect(fn () => new NativeWebSocketRouteInspector($agents)->hasCollisions(route_inspection_instance($attributes)))
        ->toThrow(ResourceOperationException::class, 'Could not inspect the current Laravel release');
    expect($agents->calls)->toBe([]);
})->with([
    'invalid user' => [['production_user' => 'Orbit Mealou']],
    'home of another user' => [['production_home' => '/home/orbit-other']],
    'invalid PHP version' => [['selected_php_version' => '8.5; id']],
]);
