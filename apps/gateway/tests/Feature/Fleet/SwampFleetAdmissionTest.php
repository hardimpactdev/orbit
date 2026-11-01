<?php

declare(strict_types=1);

use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Actions\Fleet\PlanNodeRecipeAction;
use App\Domain\Fleet\FleetWindow;
use App\Domain\Nodes\RoleName;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\InstalledFleetPrimitives;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;
use Tests\Support\PhpBuildAnswers;

function fleetRecipeReads(bool $incusChange = false): FakeAgentCommandRunner
{
    $agents = new FakeAgentCommandRunner;
    $agents->answer('recipe.inspect', static function (array $payload) use ($incusChange): array {
        $versions = [];
        foreach ($payload['tools'] as $name => $pin) {
            $changed = $incusChange && in_array($name, RecipeLoader::IncusPackages, true);
            $versions[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $changed ? 'old' : $pin['version'], 'changed' => $changed, 'status' => $changed ? 'older_than_recipe' : 'current'];
        }

        return ['_actions' => [], ...$versions];
    });
    FleetFixtures::recipeDelivery($agents);
    (new PhpBuildAnswers)->register($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    app()->instance(ReleaseAlertNotifier::class, new RecordingReleaseAlertNotifier);

    return $agents;
}

it('uses Amsterdam windows for shared roles and allows app-dev all day across DST', function (array $roles, string $utc, bool $allowed): void {
    $node = FleetFixtures::node('window-node', $roles);

    expect(app(FleetWindow::class)->allows($node, CarbonImmutable::parse($utc)))->toBe($allowed);
})->with([
    [[RoleName::AppDev], '2026-10-28T12:00:00Z', true],
    [[RoleName::AppProd], '2026-10-28T01:59:59Z', false],
    [[RoleName::AppProd], '2026-10-28T02:00:00Z', true],
    [[RoleName::AppProd], '2026-10-28T05:00:00Z', false],
    [[RoleName::Metrics], '2026-07-01T01:00:00Z', true],
    [[RoleName::Metrics], '2026-07-01T04:00:00Z', false],
    [[RoleName::AppDev, RoleName::AppProd], '2026-10-28T12:00:00Z', false],
]);

it('keeps dry-run plans available while the CLI release is pending', function (): void {
    $agents = fleetRecipeReads();
    FleetFixtures::recipeDelivery($agents, available: false);
    $node = FleetFixtures::node('recipe-plan', [RoleName::AppProd]);

    $plan = app(PlanNodeRecipeAction::class)->read($node, new RecipeLoader()->load());

    expect($plan->roles)->toBe(['app-prod'])->and($agents->commands())->toBe([]);
});

it('keeps a rollout waiting without admitting any Node until its release exists', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    InstalledFleetPrimitives::simulate();
    FleetFixtures::bind(published: false);
    FleetFixtures::node('waiting-dev', [RoleName::AppDev]);

    $plan = app(FleetRolloutPlanAction::class)->execute();

    expect($plan['status'])->toBe('waiting')->and(Operation::query()->count())->toBe(0);
});
