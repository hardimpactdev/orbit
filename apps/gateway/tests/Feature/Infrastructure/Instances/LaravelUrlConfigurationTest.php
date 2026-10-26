<?php

declare(strict_types=1);

use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\UpdateProjectData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Infrastructure\Nodes\AgentManagedUserAccountResolver;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Dotenv\Dotenv;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Orb101ProjectUpdateFixture;

it('requires one Composer Laravel declaration and one regular Artisan marker', function (): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);
    $composer = json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^13.0']], JSON_THROW_ON_ERROR);

    expect($classifier->classify($composer, ProjectType::LaravelApp, 'regular'))
        ->phpVersion->toBe('8.5')
        ->laravel->toBeTrue();
});

it('refuses partial conflicting and unsafe Laravel markers', function (array $composer, string $artisan): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);

    expect(fn () => $classifier->classify(json_encode($composer, JSON_THROW_ON_ERROR), ProjectType::LaravelApp, $artisan))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.laravel_source_invalid');
        });
})->with([
    'artisan only' => [['require' => ['php' => '^8.4']], 'regular'],
    'declaration only' => [['require' => ['laravel/framework' => '^13.0']], 'absent'],
    'duplicate declaration' => [
        [
            'require' => ['laravel/framework' => '^13.0'],
            'require-dev' => ['laravel/framework' => '^13.0'],
        ],
        'regular',
    ],
    'symlinked artisan' => [['require' => ['laravel/framework' => '^13.0']], 'unsafe'],
]);

it('leaves a Composer non-Laravel source classified as PHP only', function (): void {
    $profile = new ComposerSourceClassifier(new InstancePhpVersionCatalog)
        ->classify('{"require":{"php":"~8.4.0"}}', ProjectType::LaravelApp, 'absent');

    expect($profile->phpVersion)->toBe('8.4')->and($profile->laravel)->toBeFalse();
});

it('maps unsafe source metadata to the domain refusal', function (): void {
    [$configurator, $agents, $instance] = orb127_laravel_configurator('/home/orbit/acme');
    $agents->answer('instance.inspect-development-profile', ['kind' => 'unsafe']);

    expect(fn () => $configurator->inspect($instance))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-dev.source_metadata_unsafe'));
    expect($agents->calls)->toBe([[
        'node' => 'laravel-configurator',
        'command' => 'instance.inspect-development-profile',
        'input' => ['application' => '/home/orbit/acme', 'owner' => 'orbit', 'entry_point' => 'artisan'],
    ]]);
});

it('does not classify application metadata for an unrouted monorepo default', function (): void {
    [$configurator, $agents, $instance] = orb127_laravel_configurator('/home/orbit/acme');
    $instance->project->update(['type' => ProjectType::Monorepo]);
    $instance->update(['name' => 'default']);

    $profile = $configurator->inspect($instance);

    expect($profile->phpVersion)->toBeNull()->and($profile->laravel)->toBeFalse();
    expect($agents->calls)->toBe([]);
});

it('retains metadata safety checks for a monorepo with a Route', function (RouteStatus $status): void {
    [$configurator, $agents, $instance] = orb127_laravel_configurator('/home/orbit/acme');
    $agents->answer('instance.inspect-development-profile', ['kind' => 'unsafe']);
    $instance->project->update(['type' => ProjectType::Monorepo]);
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => 'monorepo.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update([
        'status' => $status,
        'failed_step' => $status === RouteStatus::Failed ? 'source-classification' : null,
        'error_code' => $status === RouteStatus::Failed ? 'app-dev.source_metadata_unsafe' : null,
    ]);

    expect(fn () => $configurator->inspect($instance))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-dev.source_metadata_unsafe'));
    expect($agents->commands())->toBe(['instance.inspect-development-profile']);
})->with([RouteStatus::Pending, RouteStatus::Active, RouteStatus::Failed]);

it('refuses malformed Composer metadata and conflicting Laravel declarations', function (): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);

    expect(fn () => $classifier->classify('{', ProjectType::LaravelApp, 'regular'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_version_unsupported');
        })
        ->and(fn () => $classifier->classify(json_encode([
            'require' => ['laravel/framework' => '^13.0'],
            'require-dev' => ['laravel/framework' => '^12.0'],
        ], JSON_THROW_ON_ERROR), ProjectType::LaravelApp, 'regular'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.laravel_source_invalid');
        });
});

it('sends a generated application key and quoted Project name to the managed account', function (): void {
    [$configurator, $agents, $instance] = orb127_laravel_configurator('/home/orbit/acme');
    $instance->project->update(['name' => 'Acme "Shop" $HOME']);
    $agents->answer('instance.write-development-url', ['written' => true]);

    $configurator->configureLaravelUrl($instance, 'https://feature.acme.test');

    expect($agents->commands())->toBe(['instance.write-development-url']);
    $call = $agents->calls[0];
    $input = $call['input'];
    $key = Dotenv::parse($input['app_key'])['APP_KEY'];
    expect($key)->toStartWith('base64:');
    expect(strlen(base64_decode(substr($key, 7), true)))->toBe(32);
    expect(Dotenv::parse($input['app_name']))->toBe(['APP_NAME' => 'Acme "Shop" $HOME']);
    unset($input['app_key'], $input['app_name']);
    expect($call['node'])->toBe('laravel-configurator');
    expect($input)->toBe([
        'directory' => '/home/orbit/acme', 'owner' => 'orbit', 'url' => 'https://feature.acme.test',
        'app_key_stored' => false, 'app_name_stored' => false, 'require_artisan' => false,
    ]);
});

it('sends stored application values and the requested URL on every call', function (): void {
    [$configurator, $agents, $instance] = orb127_laravel_configurator('/home/orbit/acme');
    $key = 'base64:'.base64_encode(str_repeat('k', 32));
    $instance->environmentValues()->createMany([
        ['env_key' => 'APP_KEY', 'env_value' => $key],
        ['env_key' => 'APP_NAME', 'env_value' => 'Stored Name'],
    ]);
    $agents->answer('instance.write-development-url', ['written' => false]);

    foreach (['https://feature.acme.test', 'https://next.acme.test'] as $url) {
        $configurator->configureLaravelUrl($instance, $url);
    }

    expect($agents->commands())->toBe(['instance.write-development-url', 'instance.write-development-url']);
    expect(array_column(array_column($agents->calls, 'input'), 'url'))
        ->toBe(['https://feature.acme.test', 'https://next.acme.test']);
    foreach ($agents->calls as $call) {
        expect(Dotenv::parse($call['input']['app_key']."\n".$call['input']['app_name']))
            ->toBe(['APP_KEY' => $key, 'APP_NAME' => 'Stored Name']);
        expect($call['input']['app_key_stored'])->toBeTrue()
            ->and($call['input']['app_name_stored'])->toBeTrue();
    }
});

it('maps an agent URL refusal without retrying or changing stored environment', function (): void {
    [$configurator, $agents, $instance] = orb127_laravel_configurator('/home/orbit/acme');
    $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://old.test']);
    $agents->refuse('instance.write-development-url', 'instance.release_invalid', ['check' => 'ambiguous cache']);

    expect(fn () => $configurator->configureLaravelUrl($instance, 'https://next.test'))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-dev.laravel_url_configuration_failed'));
    expect($agents->commands())->toBe(['instance.write-development-url']);
    expect($instance->environmentValues()->where('env_key', 'APP_URL')->value('env_value'))->toBe('https://old.test');
});

it('reconciles Laravel canonical URLs when a Project slug changes', function (): void {
    $fixture = Orb101ProjectUpdateFixture::bind($this);
    $fixture->defaultInstance->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://acme.test',
    ]);

    app(UpdateProjectAction::class)->execute(
        $fixture->project,
        new UpdateProjectData(
            typeProvided: false,
            type: null,
            slugProvided: true,
            slug: 'shop',
            repositoryUrlProvided: false,
            repositoryUrl: null,
            defaultBranchProvided: false,
            defaultBranch: null,
            rootProvided: false,
            root: null,
        ),
    );

    expect($fixture->projections->laravelUrls)
        ->toBe([['instance_id' => $fixture->defaultInstance->id, 'url' => 'https://shop.test']])
        ->and(InstanceEnvironmentValue::query()->where('env_key', 'APP_URL')->value('env_value'))
        ->toBe('https://shop.test');
});

it('restores Laravel URL environment on a failed slug update and ignores application errors after publication', function (): void {
    $fixture = Orb101ProjectUpdateFixture::bind($this);
    $fixture->defaultInstance->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://acme.test',
    ]);
    $fixture->projections->failSlugPrepare = true;

    expect(fn () => app(UpdateProjectAction::class)->execute(
        $fixture->project,
        new UpdateProjectData(
            typeProvided: false,
            type: null,
            slugProvided: true,
            slug: 'shop',
            repositoryUrlProvided: false,
            repositoryUrl: null,
            defaultBranchProvided: false,
            defaultBranch: null,
            rootProvided: false,
            root: null,
        ),
    ))->toThrow(ResourceOperationException::class);

    expect(InstanceEnvironmentValue::query()->where('env_key', 'APP_URL')->value('env_value'))
        ->toBe('https://acme.test');

    $fixture->projections->failSlugPrepare = false;
    $fixture->projections->applicationErrorOnUrl = true;

    expect(fn () => app(UpdateProjectAction::class)->execute(
        $fixture->project->refresh(),
        new UpdateProjectData(
            typeProvided: false,
            type: null,
            slugProvided: true,
            slug: 'shop',
            repositoryUrlProvided: false,
            repositoryUrl: null,
            defaultBranchProvided: false,
            defaultBranch: null,
            rootProvided: false,
            root: null,
        ),
    ))->toThrow(ResourceOperationException::class);

    expect($fixture->project->refresh()->slug)->toBe('acme');
});

/** @return array{RemoteDevelopmentInstanceConfigurator, FakeAgentCommandRunner, Instance} */
function orb127_laravel_configurator(string $checkoutPath): array
{
    $user = 'orbit';
    $node = Node::query()->create([
        'name' => 'laravel-configurator',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'user' => $user,
    ]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'checkout_path' => $checkoutPath,
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'status' => 'source_resolved',
    ]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    app()->bind(ManagedUserAccountResolver::class, AgentManagedUserAccountResolver::class);
    $configurator = app(RemoteDevelopmentInstanceConfigurator::class);

    return [$configurator, $agents, $instance];
}
