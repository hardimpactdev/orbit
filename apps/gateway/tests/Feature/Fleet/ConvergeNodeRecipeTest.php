<?php

declare(strict_types=1);

use App\Actions\Fleet\ConvergeNodeRecipeAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolInspectionData;
use App\Domain\Tools\ToolInspector;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\TaskVm;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\InspectsToolsIndividually;
use Tests\Support\PhpBuildAnswers;

final class RecipeVerification implements FleetNodeVerification
{
    public int $calls = 0;

    public function __construct(public bool $passed = true) {}

    public function baseline(Node $node): array
    {
        throw new LogicException('Recipe convergence does not tolerate preexisting Doctor issues.');
    }

    public function verify(Node $node, array $baseline, string $pinnedAgentVersion, array $tolerated = []): array
    {
        $this->calls++;

        return ['passed' => $this->passed, 'new_issues' => [], 'preexisting_issues' => [], 'agent_version' => $pinnedAgentVersion, 'presence' => 'matched'];
    }
}

function recipeAgents(array|AgentCommandRefused|null $application = null, ?Closure $inspection = null, ?AgentCommandRefused $incusPreflight = null): FakeAgentCommandRunner
{
    $agents = new FakeAgentCommandRunner;
    $agents->answer('recipe.inspect', $inspection ?? static function (array $payload): array {
        $versions = [];
        foreach ($payload['tools'] as $name => $pin) {
            $versions[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $name === 'curl' ? '1.0.0' : $pin['version'], 'changed' => $name === 'curl', 'status' => $name === 'curl' ? 'older_than_recipe' : 'current'];
        }

        return ['_actions' => [], ...$versions];
    });
    if ($application instanceof AgentCommandRefused) {
        $agents->refuse('recipe.apply', $application->errorCode, $application->details);
    } else {
        $agents->answer('recipe.apply', $application ?? ['changed_packages' => ['curl'], 'restarted_services' => [], 'changed_tools' => ['curl'], 'newer_than_recipe' => [], 'healthy' => true]);
    }
    if ($incusPreflight !== null) {
        $agents->refuse('recipe.incus-preflight', $incusPreflight->errorCode, $incusPreflight->details);
    } else {
        $agents->answer('recipe.incus-preflight', ['ready' => true]);
    }
    FleetFixtures::recipeDelivery($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    app()->instance(PhpBuildAnswers::class, (new PhpBuildAnswers)->register($agents));

    return $agents;
}

function recipeFailure(array $details): AgentCommandRefused
{
    return new AgentCommandRefused('recipe.apply', 'recipe.failed', 'Recipe failed.', $details);
}

it('refuses a recipe from another delivery before contacting the Node or changing its marker', function (): void {
    $node = FleetFixtures::node('stale-delivery', [RoleName::AppProd]);
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $agents = recipeAgents();
    $document = json_decode(new RecipeLoader()->load()->json, true, flags: JSON_THROW_ON_ERROR);
    $document['agent']['linux-x86_64'] = str_repeat('e', 64);
    $recipe = new RecipeLoader()->decode(json_encode($document, JSON_THROW_ON_ERROR));

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, $recipe))
        ->toThrow(ResourceOperationException::class, 'recipe agent pin differs');
    expect($agents->calls)->toBe([]);
    expect($node->refresh()->settings['machine_recipe']['version'])->toBe('previous');
});

it('keeps Node recipe execution behind the typed command boundary', function (): void {
    $source = file_get_contents(app_path('Infrastructure/MachineSoftware/NodeRecipeProgram.php'));

    foreach (['NodeShell', 'RemoteCommand', 'SshExecutor', 'RecipeProgramInput', 'resource_path(', 'Process::', 'python3', 'shell_exec'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('preserves runner refusals that are not recipe failures', function (): void {
    $node = FleetFixtures::node('recipe-agent-refusal', [RoleName::AppProd]);
    $agents = recipeAgents();
    $agents->refuse('recipe.inspect', 'agent.identity_mismatch');
    $recipe = new RecipeLoader()->load();
    $program = app(NodeRecipeProgram::class);

    expect(fn () => $program->inspect($node, $recipe, $program->tools($node, $recipe)))
        ->toThrow(AgentCommandRefused::class);
    expect($agents->commands())->toBe(['recipe.inspect'])
        ->and($node->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
});

it('plans only the production role pins and makes no remote or registry writes in dry-run', function (): void {
    $node = FleetFixtures::node('recipe-prod', [RoleName::AppProd]);
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $agents = recipeAgents();
    $recipe = new RecipeLoader()->load();

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe, dryRun: true);

    expect($result['applied'])->toBeFalse()
        ->and($result['plan']->recipe)->toBe($recipe->identity)
        ->and($result['plan']->snapshot)->toBe($recipe->snapshot)
        ->and($result['plan']->tools)->toHaveKeys(['curl', 'caddy', 'node', 'pnpm', 'vp-cli'])
        ->not->toHaveKeys(['rust', 'pi', 'php8.4-pcov'])
        ->and($result['plan']->tools['curl'])->toBe(['source' => 'ubuntu', 'version' => $recipe->tools['curl']->version, 'installed' => '1.0.0', 'changed' => true, 'status' => 'older_than_recipe'])
        ->and($result['plan']->tools['caddy']['changed'])->toBeFalse()
        ->and($agents->calls)->toHaveCount(2)
        ->and(array_column($agents->calls, 'command'))->toBe(['php.inspect-build', 'recipe.inspect'])
        ->and($node->refresh()->settings)->toBe(['machine_recipe' => ['version' => 'previous']]);
});

it('does not add operator-only tools to a Node with a production role', function (): void {
    $node = FleetFixtures::node('recipe-operator-filter', [RoleName::AppProd]);
    $document = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    $document['roles']['operator'][] = 'rust';
    $recipe = new RecipeLoader()->resolve(json_encode($document, JSON_THROW_ON_ERROR), app(AgentDelivery::class));

    $pins = app(NodeRecipeProgram::class)->tools($node, $recipe);

    expect(array_keys($pins))->not->toContain('rust');
});

it('unions multiple role tool sets without including base-only tools', function (): void {
    $node = FleetFixtures::node('recipe-multi', [RoleName::Gateway, RoleName::AppDev]);
    $recipe = new RecipeLoader()->load();

    $pins = app(NodeRecipeProgram::class)->tools($node, $recipe);

    expect(array_keys($pins))->toContain('caddy', 'curl', 'logrotate', 'php8.4-pcov', 'php8.5-fpm')
        ->not->toContain('rust', 'orbit-agent', 'pi')
        ->and(array_keys($pins))->toHaveCount(count($recipe->profile('app-dev')) + 1);
});

it('refuses a missing service role profile before SSH', function (): void {
    $node = FleetFixtures::node('recipe-vpn', [RoleName::Vpn]);
    $agents = recipeAgents();
    $document = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    unset($document['roles']['vpn']);
    $document['base'][] = 'dnsmasq';
    $incomplete = new RecipeLoader()->resolve(json_encode($document, JSON_THROW_ON_ERROR), app(AgentDelivery::class));

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, $incomplete))
        ->toThrow(InvalidArgumentException::class, 'Unknown role/profile [vpn]');
    expect($agents->calls)->toBe([]);
});

it('selects each service role prerequisite without development-only software', function (RoleName $role): void {
    $node = FleetFixtures::node('recipe-service');
    $clusterId = null;
    if (in_array($role, [RoleName::Router, RoleName::Ingress], true)) {
        $clusterId = Cluster::query()->create(['name' => 'recipe-cluster'])->id;
        $node->update(['cluster_id' => $clusterId]);
    }
    NodeRole::query()->create(['node_id' => $node->id, 'cluster_id' => $clusterId, 'role' => $role, 'status' => 'active']);
    $recipe = new RecipeLoader()->load();

    $pins = app(NodeRecipeProgram::class)->tools($node, $recipe);

    $php = in_array($role, [RoleName::WebSocket, RoleName::Vpn, RoleName::Gateway], true) ? [...$recipe->php('8.5')->packagesFor(false), 'dpkg-repack'] : [];
    $baseline = new NodeBootstrapPackageCatalog()->forRole($node, $role);
    // Recipe convergence pins the container runtime too; enrollment only asks apt for Docker.
    $runtime = in_array('docker.io', $baseline, true) ? ['containerd'] : [];
    $expected = array_unique([...$recipe->profile('operator'), ...$baseline, ...$runtime, ...$php]);
    sort($expected);
    expect(array_keys($pins))->toBe($expected)->not->toContain('rust', 'node', 'pi');
})->with([RoleName::Vpn, RoleName::Router, RoleName::Ingress, RoleName::Metrics, RoleName::Database, RoleName::WebSocket, RoleName::Analytics, RoleName::Gateway]);

it('gives every role that runs PHP the whole recipe PHP 8.5 set', function (RoleName $role): void {
    $node = FleetFixtures::node('recipe-php-'.$role->value, [$role]);
    $recipe = new RecipeLoader()->load();

    $pins = array_keys(app(NodeRecipeProgram::class)->tools($node, $recipe));

    expect($pins)->toContain(...$recipe->php('8.5')->packagesFor(false))
        ->not->toContain('php8.4-cli')
        ->and($recipe->phpExtensions($pins))->toBe(['8.5' => $recipe->php('8.5')->cliExtensions()]);
})->with([RoleName::AppProd, RoleName::WebSocket, RoleName::Gateway, RoleName::Vpn]);

it('installs the PHP build with the one installer after the other tools, strictly and without PCOV on the Gateway', function (): void {
    $node = FleetFixtures::node('recipe-php-payload', [RoleName::Gateway]);
    $agents = recipeAgents();
    $php = app(PhpBuildAnswers::class);
    app()->instance(FleetNodeVerification::class, new RecipeVerification);
    $recipe = new RecipeLoader()->load();

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe);

    $payload = collect($agents->calls)->firstWhere('command', 'recipe.apply')['input'];
    expect(array_keys($payload['tools']))->not->toContain('php8.5-fpm', 'php8.5-pcov', 'php-common')->toContain('caddy', 'dpkg-repack')
        ->and(array_column($php->inputs, 'mode'))->toBe(['plan', 'plan', 'apply'])
        ->and($php->inputs[2])->toMatchArray(['pcov' => false, 'extras' => 'refuse', 'fpm' => 'all'])
        ->and(array_keys($php->inputs[2]['versions']))->toBe(['8.5'])
        ->and($result['changes']['php'])->toMatchArray(['changed' => [], 'stale_masters' => []]);
});

it('applies the VPN FPM policy in both preflight and installation', function (array $roles, string $fpm): void {
    $node = FleetFixtures::node('recipe-vpn-policy', $roles);
    recipeAgents();
    $php = app(PhpBuildAnswers::class);
    app()->instance(FleetNodeVerification::class, new RecipeVerification);

    app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load());

    expect(array_column($php->inputs, 'mode'))->toBe(['plan', 'plan', 'apply'])
        ->and(array_values(array_unique(array_column($php->inputs, 'fpm'))))->toBe([$fpm]);
})->with([
    'VPN only' => [[RoleName::Vpn], 'disabled'],
    'VPN and websocket CLI' => [[RoleName::Vpn, RoleName::WebSocket], 'disabled'],
    'VPN and Gateway FPM' => [[RoleName::Vpn, RoleName::Gateway], 'all'],
    'VPN and application FPM' => [[RoleName::Vpn, RoleName::AppProd], 'all'],
]);

it('publishes private DNS after applying PHP only on a VPN Node', function (RoleName $role, string $outcome): void {
    $node = FleetFixtures::node('recipe-php-dns', [$role]);
    recipeAgents();
    $php = new PhpBuildAnswers(['apply' => [$outcome, ['changed' => ['php8.5-cli']]]]);
    $php->register(app(AgentCommandRunner::class));
    app()->instance(FleetNodeVerification::class, new RecipeVerification);
    $dns = Mockery::mock(PrivateDnsManager::class);
    $dns->shouldReceive('converge')->times($role === RoleName::Vpn && $outcome === 'applied' ? 1 : 0)
        ->withNoArgs()->andReturnUsing(function () use ($php): void {
            expect(array_column($php->inputs, 'mode'))->toBe(['plan', 'plan', 'apply']);
        });
    app()->instance(PrivateDnsManager::class, $dns);

    app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load());
})->with([
    'changed VPN runtime' => [RoleName::Vpn, 'applied'],
    'unchanged VPN runtime' => [RoleName::Vpn, 'current'],
    'changed application runtime' => [RoleName::AppProd, 'applied'],
]);

it('plans both pinned OpenTelemetry packages for an app-dev Node like Beast', function (): void {
    $node = FleetFixtures::node('recipe-beast', [RoleName::AppDev]);
    $agents = recipeAgents();
    $php = app(PhpBuildAnswers::class);

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), dryRun: true);

    expect($result['applied'])->toBeFalse()
        ->and($result['plan']->tools)->toHaveKeys(['php8.4-opentelemetry', 'php8.5-opentelemetry'])
        ->and($php->inputs)->toHaveCount(1)
        ->and($php->inputs[0])->toMatchArray(['mode' => 'plan', 'opentelemetry' => true, 'extras' => 'refuse']);
    foreach (['8.4', '8.5'] as $version) {
        expect(array_column($php->inputs[0]['versions'][$version]['packages'], 'name'))->toContain("php{$version}-opentelemetry");
        expect($php->inputs[0]['versions'][$version]['extensions'])->not->toContain('opentelemetry');
    }
    expect($agents->calls)->toHaveCount(2);
});

it('refuses extra PHP packages or another PHP source before any change, also in a dry run', function (bool $dryRun): void {
    $node = FleetFixtures::node('recipe-php-extras', [RoleName::AppProd]);
    $agents = recipeAgents();
    (new PhpBuildAnswers(['plan' => ['extra_packages', ['packages' => ['php8.5-opentelemetry']]]]))->register(app(AgentCommandRunner::class));

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), dryRun: $dryRun))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.recipe_php_refused')
            ->and($exception->getMessage())->toContain('php8.5-opentelemetry', 'Nothing was changed'));
    expect(collect($agents->calls)->contains('command', 'recipe.apply'))->toBeFalse();
})->with(['apply' => [false], 'dry run' => [true]]);

it('stops with the installer outcome when the PHP build rolls back and records no convergence', function (string $outcome, array $data, string $message): void {
    $node = FleetFixtures::node('recipe-php-rollback-'.$outcome, [RoleName::AppProd]);
    recipeAgents();
    (new PhpBuildAnswers(['apply' => [$outcome, $data]]))->register(app(AgentCommandRunner::class));
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.recipe_php_failed')
            ->and($exception->getMessage())->toContain('orbit-shop-php8.5-fpm.service', $message));
    expect($verification->calls)->toBe(0)
        ->and($node->refresh()->settings)->toBe([]);
})->with([
    'a checked restore' => ['rolled_back', ['unit' => 'orbit-shop-php8.5-fpm.service', 'reason' => 'the service did not start', 'restored' => true], 'restored the previous PHP build and checked it'],
    'a failed restore' => ['rollback_failed', ['unit' => 'orbit-shop-php8.5-fpm.service', 'reason' => 'the service did not start; the rollback failed too'], 'The rollback of the previous PHP build failed too'],
]);

it('selects CI software and PCOV through roles without purging host PHP packages', function (): void {
    $sabre = FleetFixtures::node('recipe-php-sabre', [RoleName::CiRunner]);
    recipeAgents();
    $php = app(PhpBuildAnswers::class);
    app()->instance(FleetNodeVerification::class, new RecipeVerification);

    app(ConvergeNodeRecipeAction::class)->execute($sabre, new RecipeLoader()->load());
    $dev = FleetFixtures::node('recipe-php-dev', [RoleName::AppDev, RoleName::CiRunner]);
    app(ConvergeNodeRecipeAction::class)->execute($dev, new RecipeLoader()->load());

    $applies = array_values(array_filter($php->inputs, static fn (array $input): bool => $input['mode'] === 'apply'));
    expect($applies[0])->toMatchArray(['pcov' => true, 'opentelemetry' => false, 'extras' => 'refuse'])
        ->and($applies[1])->toMatchArray(['pcov' => true, 'opentelemetry' => true, 'extras' => 'refuse']);
});

it('selects CI role software and replaces a recorded profile with normal recipe state', function (): void {
    $this->freezeTime();
    $node = FleetFixtures::node('recipe-sabre', [RoleName::CiRunner]);
    $node->update(['settings' => ['machine_recipe' => ['profile' => 'ci-runner']]]);
    recipeAgents();
    app()->instance(FleetNodeVerification::class, new RecipeVerification);
    $recipe = new RecipeLoader()->load();

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe);
    $later = app(ConvergeNodeRecipeAction::class)->execute($node->refresh(), $recipe, dryRun: true);

    expect(array_keys($result['plan']->tools))->toContain(...$recipe->php('8.5')->packagesFor(false))
        ->and($node->settings['machine_recipe'])->toBe(['version' => $recipe->identity, 'snapshot' => $recipe->snapshot, 'converged_at' => now()->toIso8601String()])
        ->and(array_keys($later['plan']->tools))->toBe(array_keys($result['plan']->tools))
        ->and(array_keys(app(NodeRecipeProgram::class)->tools(FleetFixtures::node('recipe-operator'), $recipe)))->not->toContain('php8.5-cli');
});

it('clears stale recipe profile state when convergence fails', function (): void {
    $node = FleetFixtures::node('recipe-sabre-fail', [RoleName::CiRunner]);
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous', 'profile' => 'ci-runner']]]);
    recipeAgents(recipeFailure(['stage' => 'recipe-stage:packages']));
    app()->instance(FleetNodeVerification::class, new RecipeVerification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'stopped at recipe-stage:packages');
    expect($node->refresh()->settings)->toBe([]);
});

it('ignores a recorded CI profile on a roleless operator Node', function (): void {
    $node = FleetFixtures::node('recipe-operator');
    $node->update(['settings' => ['machine_recipe' => ['profile' => 'ci-runner']]]);
    $recipe = new RecipeLoader()->load();

    expect(array_keys(app(NodeRecipeProgram::class)->tools($node, $recipe)))->not->toContain('php8.5-cli');
});

it('records the complete identity only after inventory health and Doctor pass and preserves other settings', function (): void {
    $this->freezeTime();
    $node = FleetFixtures::node('recipe-pass');
    $node->update(['settings' => ['t3' => ['keep' => true], 'machine_recipe' => ['version' => 'previous']]]);
    $agents = recipeAgents();
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);
    $recipe = new RecipeLoader()->load();
    $agents->answer('recipe.verify-delivery', static function (array $input) use ($verification, $node, $recipe): array {
        expect($verification->calls)->toBe(1);
        expect($node->refresh()->settings)->not->toHaveKey('machine_recipe');
        expect($input)->toBe([
            'user' => $node->user, 'identity' => $recipe->identity,
            'agent_sha256' => app(AgentDelivery::class)->pin($node), 'cli_version' => '0.4681.0',
        ]);

        return ['agent_sha256' => $input['agent_sha256'], 'cli_version' => $input['cli_version'], 'identity' => $input['identity']];
    });

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe);

    expect($result['applied'])->toBeTrue()
        ->and($result['changes']['changed_packages'])->toBe(['curl'])
        ->and($verification->calls)->toBe(1)
        ->and($node->refresh()->settings)->toBe([
            't3' => ['keep' => true],
            'machine_recipe' => ['version' => $recipe->identity, 'snapshot' => $recipe->snapshot, 'converged_at' => now()->toIso8601String()],
        ]);
    expect(array_column($agents->calls, 'command'))->toBe(['recipe.inspect', 'recipe.apply', 'recipe.verify-delivery']);
});

it('does not record a recipe when the final locked delivery check refuses', function (string $code): void {
    $node = FleetFixtures::node('recipe-final-refusal');
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $agents = recipeAgents();
    $agents->refuse('recipe.verify-delivery', $code);
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'Final recipe delivery verification failed');
    expect($node->refresh()->settings)->toBe([]);
    expect($verification->calls)->toBe(1);
    expect($agents->commands())->toBe(['recipe.inspect', 'recipe.apply', 'recipe.verify-delivery']);
})->with(['recipe.update_busy', 'recipe.update_lock_failed', 'recipe.delivery_mismatch']);

it('refuses an unavailable requested CLI release before application', function (): void {
    $node = FleetFixtures::node('recipe-unpublished');
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $agents = recipeAgents();
    FleetFixtures::recipeDelivery($agents, available: false);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'requested CLI release to be available');
    expect($agents->commands())->toBe(['recipe.inspect']);
    expect($node->refresh()->settings['machine_recipe']['version'])->toBe('previous');
});

it('does not record an unexpected verification reply', function (): void {
    $node = FleetFixtures::node('recipe-invalid-verification');
    $agents = recipeAgents();
    $agents->answerMalformed('recipe.verify-delivery', ['identity' => 'wrong']);
    app()->instance(FleetNodeVerification::class, new RecipeVerification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'returned another delivery');
    expect($node->refresh()->settings)->toBe([]);
});

it('stops after health or package failure with no retry rollback Doctor or success marker', function (string $stage): void {
    $node = FleetFixtures::node('recipe-fail');
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $agents = recipeAgents(recipeFailure(['stage' => 'recipe-stage:'.$stage]));
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'stopped at recipe-stage:'.$stage);
    expect($verification->calls)->toBe(0)
        ->and($node->refresh()->settings)->toBe([])
        ->and($agents->calls)->toHaveCount(2);
})->with(['packages', 'health', 'restart', 'inventory', 'download']);

it('stops on Doctor failure without rollback or recording the recipe', function (): void {
    $node = FleetFixtures::node('recipe-doctor-fail');
    $agents = recipeAgents();
    $verification = new RecipeVerification(passed: false);
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'stopped at Doctor');
    expect($node->refresh()->settings)->toBe([])
        ->and($verification->calls)->toBe(1)
        ->and($agents->calls)->toHaveCount(2);
});

it('refuses conflicting managed apt intent before any SSH or mutation', function (): void {
    $node = FleetFixtures::node('recipe-conflict', [RoleName::AppProd]);
    $manager = ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'git', 'version_constraint' => '<1.0', 'status' => 'installed']);
    $agents = recipeAgents();

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'conflicts with managed Tool');
    expect($agents->calls)->toBe([]);
});

it('checks the existing managed tool inventory before Doctor and leaves tool intent unchanged', function (): void {
    $node = FleetFixtures::node('recipe-tool-fail');
    $manager = ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'composer', 'status' => 'active']);
    $tool = Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'vendor/tool', 'status' => 'installed']);
    $agents = recipeAgents();
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(false, null);
        }
    };
    app()->instance(ToolInspector::class, $inspector);
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'managed Tool');
    expect($tool->refresh()->status->value)->toBe('installed')
        ->and($node->refresh()->settings)->toBe([])
        ->and($verification->calls)->toBe(0)
        ->and($agents->calls)->toHaveCount(2);
});

it('runs the command dry-run by Node name or ID without changing the Node', function (bool $useId): void {
    $node = FleetFixtures::node('recipe-command', [RoleName::AppProd]);
    $agents = recipeAgents();

    $exit = Artisan::call('node:converge-recipe', ['node' => $useId ? (string) $node->id : $node->name, '--dry-run' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output['applied'])->toBeFalse()
        ->and($output['plan']['tools']['curl']['changed'])->toBeTrue();
    expect($agents->calls)->toHaveCount(2)
        ->and($node->refresh()->settings)->toBeNull();
})->with([false, true]);

it('refuses a different requested identity before reaching a Node', function (): void {
    $this->artisan('node:converge-recipe', ['node' => 'missing', '--recipe' => 'sha256:wrong', '--dry-run' => true])
        ->expectsOutputToContain('does not match the checked-out recipe')->assertFailed();
});

it('lists mixed recipe identities from the registry without SSH', function (): void {
    $current = FleetFixtures::node('current');
    $current->update(['settings' => ['machine_recipe' => ['version' => 'sha256:current', 'snapshot' => '20261014T000000Z']]]);
    FleetFixtures::node('pending');

    $exit = Artisan::call('node:converge-recipe', ['--status' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output[0]['machine_recipe']['version'])->toBe('sha256:current')
        ->and($output[1]['machine_recipe'])->toBeNull();
});

it('keeps newer Ubuntu versions visible in the plan result and recorded status', function (): void {
    $node = FleetFixtures::node('recipe-newer');
    $recipe = new RecipeLoader()->load();
    $newer = ['curl' => ['installed' => '8.18.0-1ubuntu2.8', 'version' => $recipe->tools['curl']->version]];
    recipeAgents(['changed_packages' => [], 'restarted_services' => [], 'changed_tools' => [], 'healthy' => true, 'newer_than_recipe' => $newer], static function (array $payload): array {
        $tools = [];
        foreach ($payload['tools'] as $name => $pin) {
            $tools[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $name === 'curl' ? '8.18.0-1ubuntu2.8' : $pin['version'], 'changed' => false, 'status' => $name === 'curl' ? 'newer_than_recipe' : 'current'];
        }

        return ['_actions' => [], ...$tools];
    });
    app()->instance(FleetNodeVerification::class, new RecipeVerification);

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe);

    expect($result['plan']->tools['curl'])->toMatchArray(['changed' => false, 'status' => 'newer_than_recipe', 'installed' => '8.18.0-1ubuntu2.8'])
        ->and($result['changes']['newer_than_recipe'])->toBe($newer)
        ->and($node->refresh()->settings['machine_recipe']['newer_than_recipe'])->toBe($newer);
});

it('retains redacted package command output in the failed convergence result', function (string $stage): void {
    $node = FleetFixtures::node('recipe-package-output');
    recipeAgents(recipeFailure([
        'stage' => 'recipe-stage:'.$stage, 'packages' => ['curl=8.18.0-1ubuntu2.7'],
        'command' => ['apt-get', 'install', 'curl=8.18.0-1ubuntu2.7'], 'exit_code' => 100,
        'stdout' => 'curl: dependency conflict',
        'stderr' => 'Failed to fetch https://user:secret@example.test/pkg API_TOKEN=private-token',
    ]));

    $exit = Artisan::call('node:converge-recipe', ['node' => $node->name]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['message'])->toContain('recipe-stage:'.$stage, 'curl=8.18.0-1ubuntu2.7', 'apt-get', 'dependency conflict', '[REDACTED]')
        ->and($result['automatic_rollback'])->toBeFalse();
    expect($result['message'])->not->toContain('user:secret');
    expect($result['message'])->not->toContain('private-token');
    expect($node->refresh()->settings)->toBe([]);
})->with(['packages', 'apt-source', 'tools', 'restart:nginx', 'health:php8.5-fpm']);

it('retains a source validation reason without requiring command output', function (): void {
    $node = FleetFixtures::node('recipe-source-validation');
    recipeAgents(recipeFailure([
        'stage' => 'recipe-stage:apt-source',
        'reason' => 'Backup [/etc/apt/ubuntu.sources.orbit-snapshot-backup] differs from the source. API_TOKEN=private-token',
    ]));

    $exit = Artisan::call('node:converge-recipe', ['node' => $node->name]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['message'])->toContain('recipe-stage:apt-source', '/etc/apt/ubuntu.sources.orbit-snapshot-backup', 'differs from the source', '[REDACTED]')
        ->and($result['automatic_rollback'])->toBeFalse();
    expect($result['message'])->not->toContain('private-token');
    expect($node->refresh()->settings)->toBe([]);
});

it('shows a source repair and an unsupported newer Ubuntu build as planned actions without applying them', function (?string $reason): void {
    $node = FleetFixtures::node('recipe-refused');
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $agents = recipeAgents(inspection: static function (array $payload) use ($reason): array {
        $tools = [];
        foreach ($payload['tools'] as $name => $pin) {
            $tools[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $name === 'curl' ? '99.0-foreign' : $pin['version'], 'changed' => false, 'status' => $name === 'curl' ? 'unsupported_newer' : 'current'];
        }
        if ($reason !== null) {
            $tools['curl']['refusal'] = 'origin:curl:'.$reason;
        }

        return [...$tools, '_actions' => ['repair_ubuntu_sources', 'retire_git_source', 'pin_git', 'remove_nodejs_package', 'retire_node_source', 'retire_node_config', 'remove_corepack_links']];
    });

    $exit = Artisan::call('node:converge-recipe', ['node' => $node->name, '--dry-run' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result['applied'])->toBeFalse()
        ->and($result['plan']['actions'])->toBe(['repair_ubuntu_sources', 'retire_git_source', 'pin_git', 'remove_nodejs_package', 'retire_node_source', 'retire_node_config', 'remove_corepack_links'])
        ->and($result['plan']['tools']['curl'])->toMatchArray(['status' => 'unsupported_newer', 'changed' => false, 'refusal' => 'origin:curl'.($reason === null ? '' : ':'.$reason)])
        ->and($node->refresh()->settings['machine_recipe']['version'])->toBe('previous');
    expect(collect($agents->calls)->contains('command', 'recipe.apply'))->toBeFalse();
})->with([null, 'dpkg-log-missing', 'snapshot-unavailable', 'snapshot-unproved', 'snapshot-lookup-limit']);

it('includes the exact native Incus package set only on configured task VM hosts', function (string $architecture, string $version): void {
    $node = FleetFixtures::node('recipe-incus-host', [RoleName::Database]);
    $node->update(['architecture' => $architecture, 'settings' => ['task_vms' => ['capacity' => 1]]]);
    $recipe = new RecipeLoader()->load();

    $tools = app(NodeRecipeProgram::class)->tools($node, $recipe);

    foreach (RecipeLoader::IncusPackages as $name) {
        expect($tools[$name]->version)->toBe($version);
    }
    $node->update(['settings' => []]);
    expect(array_keys(app(NodeRecipeProgram::class)->tools($node, $recipe)))->not->toContain('incus');
})->with([
    ['x86_64', '1:7.0.1-ubuntu26.04-202609250211'],
    ['aarch64', '1:7.0.1-ubuntu26.04-202609250512'],
]);

function incusRecipeInspection(array $payload, bool $phpChange = false): array
{
    $tools = [];
    foreach ($payload['tools'] as $name => $pin) {
        $changed = in_array($name, RecipeLoader::IncusPackages, true) || ($phpChange && $name === 'php8.5-cli');
        $tools[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $changed ? 'previous-build' : $pin['version'], 'changed' => $changed, 'status' => $changed ? 'different' : 'current'];
    }

    return ['_actions' => [], ...$tools];
}

it('applies other pins automatically and records the held Incus pins without a full identity', function (): void {
    $node = FleetFixtures::node('incus-automatic', [RoleName::AppDev]);
    $node->update(['settings' => ['task_vms' => [], 'machine_recipe' => ['version' => 'previous']]]);
    $agents = recipeAgents(inspection: static fn (array $input): array => incusRecipeInspection($input, phpChange: true));
    $php = new PhpBuildAnswers(['apply' => ['applied', ['changed' => ['php8.5-cli']]]]);
    $php->register(app(AgentCommandRunner::class));
    app()->instance(FleetNodeVerification::class, new RecipeVerification);
    $recipe = new RecipeLoader()->load();

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe, automatic: true);

    expect($result['changes']['changed_packages'])->toBe(['curl', 'php8.5-cli'])
        ->and($node->refresh()->settings['machine_recipe'])->toHaveKey('partial_version', $recipe->identity)->not->toHaveKey('version')
        ->and($node->settings['machine_recipe_incus_hold']['pins'])->toHaveKeys(RecipeLoader::IncusPackages);
    expect(array_column($php->inputs, 'mode'))->toBe(['plan', 'plan', 'apply']);
    expect(array_column($php->inputs[2]['versions']['8.5']['packages'], 'name'))->toContain('php8.5-cli');
    foreach ($agents->calls as $command) {
        if ($command['command'] === 'recipe.apply') {
            expect($command['input']['tools'])->toHaveKey('curl')->not->toHaveKeys(RecipeLoader::IncusPackages);
        }
    }
});

it('names active instances in other projects and preserves settings on explicit refusal', function (): void {
    $node = FleetFixtures::node('incus-active');
    $settings = ['task_vms' => [], 'machine_recipe' => ['version' => 'previous']];
    $node->update(['settings' => $settings]);
    $agents = recipeAgents(inspection: static fn (array $input): array => incusRecipeInspection($input), incusPreflight: recipeFailure(['stage' => 'recipe-stage:incus:running', 'reason' => 'Drain active instances: orbit-ci/ci-shark-1 (container, Running), memory/normal (virtual-machine, Running)']));

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'orbit-ci/ci-shark-1');

    expect($node->refresh()->settings)->toBe($settings);
    expect(collect($agents->calls)->where('command', 'recipe.apply'))->toBeEmpty();
});

it('refuses an Incus change while a task VM row can still resume or provision', function (string $state): void {
    $node = FleetFixtures::node('incus-live-row');
    $node->update(['settings' => ['task_vms' => []]]);
    $vm = TaskVm::query()->create(['host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'live-test-vm', 'wireguard_ip' => '10.99.0.1', 'state' => $state]);
    $agents = recipeAgents(inspection: static fn (array $input): array => incusRecipeInspection($input));

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, $vm->name.' ('.$state.')');
    expect(array_column($agents->calls, 'command'))->toBe(['recipe.inspect']);
})->with(['provisioning', 'ready', 'parked', 'destroying', 'failed']);
