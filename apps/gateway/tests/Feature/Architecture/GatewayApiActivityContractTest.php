<?php

declare(strict_types=1);

use App\Contracts\Loggable;
use App\Http\Controllers\Api\CodexAppController;
use App\Http\Controllers\Api\DeployController;
use App\Http\Controllers\Api\ExtensionDisableController;
use App\Http\Controllers\Api\ExtensionEnableController;
use App\Http\Controllers\Api\ExtensionListController;
use App\Http\Controllers\Api\InternalExecutorTokenController;
use App\Http\Controllers\Api\NodeBootstrapCompleteController;
use App\Http\Controllers\Api\NodeBootstrapController;
use App\Http\Controllers\Api\NodeBootstrapResumeController;
use App\Http\Controllers\Api\NodeManageController;
use App\Http\Middleware\LogActivity;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

it('requires the canonical activity controllers behind LogActivity to be Loggable', function (string $controller): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(
            fn (RoutingRoute $route): bool => (
                str_starts_with($route->getActionName(), $controller.'@')
                || $route->getActionName() === $controller
            ),
        );

    expect($routes)->not->toBeEmpty();
    expect($routes->every(fn (RoutingRoute $route): bool => in_array(
        LogActivity::class,
        $route->gatherMiddleware(),
        strict: true,
    )))->toBeTrue();
    expect(is_subclass_of($controller, Loggable::class))->toBeTrue();
})->with([
    DeployController::class,
    ExtensionListController::class,
    ExtensionEnableController::class,
    ExtensionDisableController::class,
    CodexAppController::class,
    NodeManageController::class,
]);

it('keeps explicit separate-emission API flows outside the Loggable controller contract', function (string $controller): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => $route->getActionName() === $controller);

    expect($routes)->not->toBeEmpty();
    expect($routes->every(fn (RoutingRoute $route): bool => in_array(
        LogActivity::class,
        $route->gatherMiddleware(),
        strict: true,
    )))->toBeTrue();
    expect(is_subclass_of($controller, Loggable::class))->toBeFalse();
})->with([
    'internal executor token verification is not durable product state' => InternalExecutorTokenController::class,
    'bootstrap reservation emits only after terminal completion' => NodeBootstrapController::class,
    'bootstrap resume emits only after terminal completion' => NodeBootstrapResumeController::class,
    'bootstrap completion emits NodeBootstrapCompletedActivity directly' => NodeBootstrapCompleteController::class,
]);
