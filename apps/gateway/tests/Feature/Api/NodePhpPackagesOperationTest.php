<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
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
    if (! OperationKinds::supports('list_of')) {
        $this->markTestSkipped('Bounded warning lists require Kit C.');
    }
    $gateway = $this->markAsGateway(FleetFixtures::node('gateway', [RoleName::Gateway]));
    $services = FleetFixtures::node('services');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $spec = ['executor' => 'swamp', 'completion' => PhpPackagesParent::class, 'execution' => PhpPackagesParent::class, 'runs' => [],
        'children' => [['kind' => 'node.prepare_php_packages', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['children_succeeded' => ['node.prepare_php_packages']], 'result_fields' => []];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'instance.deploy' => $spec, 'instance.rollback' => $spec]));
});

function phpPackagesAdmit($test, Node $node, array $versions = ['8.5'], string $kind = 'instance.deploy'): TestResponse
{
    $parent = app(StartOperationAction::class)->execute($kind, 'node', $node->id, $node->id, [], 'test', null);
    $parent->update(['state' => ['child_intents' => [['key' => 'packages', 'kind' => 'node.prepare_php_packages', 'node_id' => $node->id, 'input' => ['versions' => $versions, 'profile' => 'app-prod']]]]]);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'parent'])->assertOk();

    return $test->postJson("/api/v1/operation-executor/{$parent->id}/children", ['worker' => 'parent', 'key' => 'packages']);
}

function phpPackagesChild($test, Node $node, string $kind = 'instance.deploy'): Operation
{
    $id = phpPackagesAdmit($test, $node, kind: $kind)->assertAccepted()->json('data.id');

    return Operation::findOrFail($id);
}

it('captures the package-only recipe at admission without executing it', function (bool $development): void {
    $node = FleetFixtures::node('packages', $development ? [RoleName::AppProd, RoleName::AppDev] : [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    $operation = phpPackagesChild($this, $node);

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

    phpPackagesAdmit($this, $node, $versions)->assertUnprocessable()->assertJsonPath('error.code', 'node.php_packages_invalid');
    expect(Operation::query()->where('kind', 'node.prepare_php_packages')->count())->toBe(0);
})->with([[[]], [['8.5', '8.5']], [['8.5; command']], [['one' => '8.5']], [array_map(fn ($i) => '8.'.$i, range(0, 8))]]);

it('refuses unsupported versions without admitting a package child', function (): void {
    $node = FleetFixtures::node('unsupported-packages');
    phpPackagesAdmit($this, $node, ['99.1'])->assertJsonPath('error.code', 'app-prod.php_package_source_unavailable');
    expect(Operation::query()->where('kind', 'node.prepare_php_packages')->count())->toBe(0);
});

it('binds the serving Node and every installer policy field to admitted intent', function (string $field, mixed $value): void {
    $node = FleetFixtures::node('bound-packages', [RoleName::AppProd]);
    $operation = phpPackagesChild($this, $node);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk();
    $input = [...$operation->input['_php_packages'], $field => $value];

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", ['worker' => 'packages', 'key' => 'php-packages',
        'transport' => 'agent', 'name' => 'php.install-build', 'input' => $input])->assertUnprocessable();
})->with([['fpm', 'shared'], ['extras', 'purge'], ['pcov', true], ['mirror', 'https://unadmitted.example.test'], ['versions', []], ['node_id', 999]]);

it('requires proven installer success and replays redacted completion without another effect', function (string $outcome): void {
    $node = FleetFixtures::node('proven-packages', [RoleName::AppProd]);
    $operation = phpPackagesChild($this, $node);
    $node->roles()->create(['role' => RoleName::AppDev]);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('php.install-build', PhpBuildAnswers::result($outcome));
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk();
    $path = "/api/v1/operation-executor/{$operation->id}";
    $complete = ['worker' => 'packages', 'status' => 'succeeded', 'result' => ['outcome' => $outcome, 'stale_masters' => [], 'deferred' => []]];
    $this->postJson($path.'/complete', $complete)->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $run = ['worker' => 'packages', 'key' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'input' => $operation->input['_php_packages']];

    $this->postJson($path.'/run', $run)->assertOk()->assertJsonPath('data', ['outcome' => $outcome, 'stale_masters' => [], 'deferred' => []]);
    $this->postJson($path.'/step', ['worker' => 'packages', 'key' => 'finish', 'name' => 'php-packages', 'status' => 'succeeded', 'result' => ['outcome' => $outcome, 'stale_masters' => [], 'deferred' => []]])->assertOk();
    $this->postJson($path.'/complete', $complete)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->postJson($path.'/complete', $complete)->assertOk();
    expect($agents->calls)->toHaveCount(1)->and($agents->calls[0]['node'])->toBe($node->name)
        ->and($agents->calls[0]['input']['pcov'])->toBeFalse()->and($agents->calls[0]['input']['fpm'])->toBe('none');
    expect($operation->refresh()->result)->toBe(['outcome' => $outcome, 'stale_masters' => [], 'deferred' => []]);
})->with(['current', 'applied']);

it('admits and replays a deploy or rollback child under its parent hold', function (string $kind): void {
    $node = FleetFixtures::node('child-packages', [RoleName::AppProd]);
    $child = phpPackagesChild($this, $node, $kind);
    $path = "/api/v1/operation-executor/{$child->parent_operation_id}/children";
    $this->postJson($path, ['worker' => 'parent', 'key' => 'packages'])->assertOk()->assertJsonPath('data.id', $child->id);
    $this->postJson($path, ['worker' => 'parent', 'key' => 'packages', 'input' => ['versions' => ['8.4']]])->assertConflict();
    expect($child->node_id)->toBe($node->id)->and($child->lock_key)->toBeNull()
        ->and(Operation::findOrFail($child->parent_operation_id)->lock_key)->not->toBeNull();
})->with(['instance.deploy', 'instance.rollback']);

it('refuses a proven response that did not apply or confirm the packages', function (): void {
    $node = FleetFixtures::node('unproven-packages', [RoleName::AppProd]);
    $operation = phpPackagesChild($this, $node);
    $agents = new FakeAgentCommandRunner;
    $agents->answerMalformed('php.install-build', ['outcome' => 'planned', 'data' => []]);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk();
    $path = "/api/v1/operation-executor/{$operation->id}";
    $this->postJson($path.'/run', ['worker' => 'packages', 'key' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'input' => $operation->input['_php_packages']])->assertOk();
    $this->postJson($path.'/step', ['worker' => 'packages', 'key' => 'finish', 'name' => 'php-packages', 'status' => 'succeeded', 'result' => ['outcome' => 'planned', 'stale_masters' => [], 'deferred' => []]])->assertOk();

    $this->postJson($path.'/complete', ['worker' => 'packages', 'status' => 'succeeded', 'result' => ['outcome' => 'planned', 'stale_masters' => [], 'deferred' => []]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect($operation->refresh()->result)->toBeNull()->and($operation->lock_key)->not->toBeNull();
});

it('admits a managed production Node marked failed', function (): void {
    $node = FleetFixtures::node('failed-packages', [RoleName::AppProd], status: LifecycleStatus::Failed);
    expect(phpPackagesChild($this, $node)->node_id)->toBe($node->id);
});

it('refuses expired admissions or changed recipe revisions before any remote call', function (string $case): void {
    $node = FleetFixtures::node('stale-packages', [RoleName::AppProd]);
    $operation = phpPackagesChild($this, $node);
    if ($case === 'deadline') {
        $this->travel(121)->seconds();
    } else {
        $recipe = new RecipeLoader()->load();
        $changed = new MachineRecipe(...[...get_object_vars($recipe), 'canonicalJson' => $recipe->canonicalJson.' ']);
        app()->instance(RemotePhpPackageManager::class, new RemotePhpPackageManager(recipe: $changed));
    }
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk()->assertJsonPath('data.id', $operation->id);
    $path = "/api/v1/operation-executor/{$operation->id}";

    $this->postJson($path.'/run', ['worker' => 'packages', 'key' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'input' => $operation->input['_php_packages']])
        ->assertConflict()->assertJsonPath('error.code', 'app-prod.php_package_source_unavailable');
    $this->postJson($path.'/complete', ['worker' => 'packages', 'status' => 'failed', 'result' => ['error_code' => 'app-prod.php_package_source_unavailable'], 'error_code' => 'app-prod.php_package_source_unavailable'])
        ->assertOk()->assertJsonPath('data.status', 'failed');
    expect($agents->calls)->toBe([]);
})->with(['deadline', 'revision']);

it('retains and logs only bounded master warning lists', function (): void {
    $node = FleetFixtures::node('warn-packages', [RoleName::AppProd]);
    $operation = phpPackagesChild($this, $node);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('php.install-build', PhpBuildAnswers::result('applied', ['stale_masters' => ['orbit-fpm-a.service'], 'deferred' => ['orbit-fpm-b.service']]));
    app()->instance(AgentCommandRunner::class, $agents);
    Log::spy();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk();
    $path = "/api/v1/operation-executor/{$operation->id}";

    $result = $this->postJson($path.'/run', ['worker' => 'packages', 'key' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'input' => $operation->input['_php_packages']])
        ->assertOk()->assertJsonPath('data.stale_masters', ['orbit-fpm-a.service'])->assertJsonPath('data.deferred', ['orbit-fpm-b.service'])->json('data');
    $this->postJson($path.'/step', ['worker' => 'packages', 'key' => 'finish', 'name' => 'php-packages', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'packages', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($operation->refresh()->result)->toBe($result)->not->toHaveKey('data');
    Log::shouldHaveReceived('warning')->twice();
});

it('fails malformed installer data and releases the composed parent after refusal', function (mixed $data): void {
    $node = FleetFixtures::node('bad-data-packages', [RoleName::AppProd]);
    $operation = phpPackagesChild($this, $node);
    $agents = new FakeAgentCommandRunner;
    $agents->answerMalformed('php.install-build', ['outcome' => 'current', 'data' => $data]);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'packages'])->assertOk();
    $path = "/api/v1/operation-executor/{$operation->id}";
    $result = $this->postJson($path.'/run', ['worker' => 'packages', 'key' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'input' => $operation->input['_php_packages']])
        ->assertOk()->assertJsonPath('data.error_code', 'app-prod.php_install_failed')->json('data');
    $this->postJson($path.'/step', ['worker' => 'packages', 'key' => 'finish', 'name' => 'php-packages', 'status' => 'failed', 'result' => $result, 'error_code' => 'app-prod.php_install_failed'])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'packages', 'status' => 'failed', 'result' => $result, 'error_code' => 'app-prod.php_install_failed'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$operation->parent_operation_id}/complete", ['worker' => 'parent', 'status' => 'failed', 'error_code' => 'app-prod.php_install_failed'])->assertOk();
    expect($operation->refresh()->status->value)->toBe('failed')
        ->and(Operation::findOrFail($operation->parent_operation_id)->lock_key)->toBeNull();
})->with([null, 'not-an-array', ['stale_masters' => 'not-a-list'], ['deferred' => ['bad' => 'map']]]);
