<?php

declare(strict_types=1);

use App\Actions\Nodes\StartNodePhpPackagesAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\PhpBuildAnswers;

final class PhpPackagesParent implements OperationCompletion, OperationFamilyChildren, OperationFamilyExecution
{
    public function childContext(Operation $parent): array
    {
        return ['intent' => [], 'children' => $parent->state['child_intents']];
    }

    public function context(Operation $operation): array
    {
        return ['descriptors' => []];
    }

    public function input(Operation $operation, array $run): array
    {
        return [];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

beforeEach(function (): void {
    $gateway = $this->markAsGateway(FleetFixtures::node('gateway', [RoleName::Gateway]));
    $services = FleetFixtures::node('services');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
});

it('captures the package-only recipe at admission without executing it', function (bool $development): void {
    $node = FleetFixtures::node('packages', $development ? [RoleName::AppProd, RoleName::AppDev] : [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    $operation = app(StartNodePhpPackagesAction::class)->execute($node, ['8.5']);

    $snapshot = $operation->input['_php_packages'];
    $recipe = new RecipeLoader()->load();
    expect($operation->node_id)->toBe($node->id)->and($operation->target_id)->toBe($node->id);
    expect($snapshot)->toMatchArray(['extras' => 'keep', 'fpm' => 'none', 'pcov' => $development, 'opentelemetry' => $development,
        'mirror' => $recipe->artifactMirror, 'release' => ['id' => 'ubuntu', 'codenames' => ['resolute']]])
        ->and(array_keys($snapshot['versions']))->toBe(['8.5']);
    foreach ($snapshot['versions']['8.5']['packages'] as $package) {
        expect($package['version'])->toBe($recipe->tools[$package['name']]->version)
            ->and($package['sha256'])->toBe($recipe->tools[$package['name']]->artifacts[$package['architecture']]->digest);
    }
    AgentSchemas::assertInput('php.install-build', $snapshot);
    expect($agents->calls)->toBe([]);
})->with([false, true]);

it('refuses invalid selections before reserving the Node', function (array $versions): void {
    $node = FleetFixtures::node('invalid-packages');

    expect(fn () => app(StartNodePhpPackagesAction::class)->execute($node, $versions))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 0);
})->with([[[]], [['8.5', '8.5']], [['8.5; command']], [['one' => '8.5']], [array_map(fn ($i) => '8.'.$i, range(0, 8))]]);

it('refuses unsupported versions and unmanaged Nodes without admitting work', function (): void {
    $node = FleetFixtures::node('unsupported-packages');
    expect(fn () => app(StartNodePhpPackagesAction::class)->execute($node, ['99.1']))
        ->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe('app-prod.php_package_source_unavailable'));
    $node->update(['platform' => 'macos']);
    expect(fn () => app(StartNodePhpPackagesAction::class)->execute($node, ['8.5']))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 0);
});

it('binds the serving Node and every installer policy field to admitted intent', function (string $field, mixed $value): void {
    $node = FleetFixtures::node('bound-packages', [RoleName::AppProd]);
    $operation = app(StartNodePhpPackagesAction::class)->execute($node, ['8.5']);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk();
    $input = [...$operation->input['_php_packages'], $field => $value];

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", ['worker' => 'packages', 'key' => 'php-packages',
        'transport' => 'agent', 'name' => 'php.install-build', 'input' => $input])->assertUnprocessable();
})->with([['fpm', 'shared'], ['extras', 'purge'], ['pcov', true], ['mirror', 'https://unadmitted.example.test'], ['versions', []], ['node_id', 999]]);

it('requires proven installer success and replays redacted completion without another effect', function (string $outcome): void {
    $node = FleetFixtures::node('proven-packages', [RoleName::AppProd]);
    $operation = app(StartNodePhpPackagesAction::class)->execute($node, ['8.5']);
    $node->roles()->create(['role' => RoleName::AppDev]);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('php.install-build', PhpBuildAnswers::result($outcome));
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk();
    $path = "/api/v1/operation-executor/{$operation->id}";
    $complete = ['worker' => 'packages', 'status' => 'succeeded', 'result' => ['outcome' => $outcome]];
    $this->postJson($path.'/complete', $complete)->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $run = ['worker' => 'packages', 'key' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'input' => $operation->input['_php_packages']];

    $this->postJson($path.'/run', $run)->assertOk()->assertJsonPath('data', ['outcome' => $outcome]);
    $this->postJson($path.'/step', ['worker' => 'packages', 'key' => 'finish', 'name' => 'php-packages', 'status' => 'succeeded', 'result' => ['outcome' => $outcome]])->assertOk();
    $this->postJson($path.'/complete', $complete)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->postJson($path.'/complete', $complete)->assertOk();
    expect($agents->calls)->toHaveCount(1)->and($agents->calls[0]['node'])->toBe($node->name)
        ->and($agents->calls[0]['input']['pcov'])->toBeFalse()->and($agents->calls[0]['input']['fpm'])->toBe('none');
    expect($operation->refresh()->result)->toBe(['outcome' => $outcome]);
})->with(['current', 'applied']);

it('admits the exact stored child once under its parent hold', function (): void {
    $node = FleetFixtures::node('child-packages', [RoleName::AppProd]);
    $spec = ['executor' => 'swamp', 'completion' => PhpPackagesParent::class, 'execution' => PhpPackagesParent::class, 'runs' => [],
        'children' => [['kind' => 'node.prepare_php_packages', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['children_succeeded' => ['node.prepare_php_packages']], 'result_fields' => []];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'test.packages_parent' => $spec]));
    $parent = app(StartOperationAction::class)->execute('test.packages_parent', 'node', $node->id, $node->id, [], 'test', null);
    $parent->update(['state' => ['child_intents' => [['key' => 'packages', 'kind' => 'node.prepare_php_packages', 'node_id' => $node->id, 'input' => ['versions' => ['8.5'], 'profile' => 'app-prod']]]]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'parent'])->assertOk();
    $path = "/api/v1/operation-executor/{$parent->id}/children";

    $id = $this->postJson($path, ['worker' => 'parent', 'key' => 'packages'])->assertAccepted()->json('data.id');
    $this->postJson($path, ['worker' => 'parent', 'key' => 'packages'])->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson($path, ['worker' => 'parent', 'key' => 'packages', 'input' => ['versions' => ['8.4']]])->assertConflict();
    $child = Operation::findOrFail($id);
    expect($child->parent_operation_id)->toBe($parent->id)->and($child->node_id)->toBe($node->id)
        ->and($child->lock_key)->toBeNull()->and($parent->refresh()->lock_key)->not->toBeNull();
    expect($child->input['_php_packages']['fpm'])->toBe('none');
});

it('refuses a proven response that did not apply or confirm the packages', function (): void {
    $node = FleetFixtures::node('unproven-packages', [RoleName::AppProd]);
    $operation = app(StartNodePhpPackagesAction::class)->execute($node, ['8.5']);
    $agents = new FakeAgentCommandRunner;
    $agents->answerMalformed('php.install-build', ['outcome' => 'planned']);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk();
    $path = "/api/v1/operation-executor/{$operation->id}";
    $this->postJson($path.'/run', ['worker' => 'packages', 'key' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'input' => $operation->input['_php_packages']])->assertOk();
    $this->postJson($path.'/step', ['worker' => 'packages', 'key' => 'finish', 'name' => 'php-packages', 'status' => 'succeeded', 'result' => ['outcome' => 'planned']])->assertOk();

    $this->postJson($path.'/complete', ['worker' => 'packages', 'status' => 'succeeded', 'result' => ['outcome' => 'planned']])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect($operation->refresh()->result)->toBeNull()->and($operation->lock_key)->not->toBeNull();
});

it('retained callers await Swamp and refuse failed or cancelled prerequisites', function (string $status, ?string $code): void {
    $node = FleetFixtures::node('awaited-packages', [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($status, $code): void {
        Operation::query()->sole()->update(['status' => $status, 'error_code' => $code, 'result' => ['outcome' => 'current']]);
    });
    $call = fn () => app(RemotePhpPackageManager::class)->installPackagesOnlyForAppProd($node, collect(['8.5']));

    try {
        if ($status === 'succeeded') {
            $call();
        } else {
            expect($call)->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe($code ?? 'operation.cancelled'));
        }
    } finally {
        Sleep::fake(false);
    }
    expect($agents->calls)->toBe([])->and(Operation::query()->sole()->kind)->toBe('node.prepare_php_packages');
})->with([['succeeded', null], ['failed', 'app-prod.php_package_source_unavailable'], ['cancelled', null]]);
