<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Nodes\ManagedUserAccount;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

it('selects the application directory for PHP inspection URL writing and FPM', function (string $root, string $relative): void {
    $checkout = '/home/orbit/acme';
    $application = $checkout.$relative;
    [$configurator, $instance, $account, $agents] = nested_application_configurator($checkout, $root);
    $agents->answer('instance.inspect-development-profile', [
        'kind' => 'composer', 'entry_point' => 'regular',
        'composer' => base64_encode('{"require":{"php":"~8.4.0","laravel/framework":"^13.0"}}'),
    ])->answer('instance.write-development-url', ['written' => true]);

    $profile = $configurator->inspect($instance);
    $configurator->configureLaravelUrl($instance, 'https://nested.test');

    expect($profile->phpVersion)->toBe('8.4')->and($profile->laravel)->toBeTrue();
    expect($agents->commands())->toBe(['instance.inspect-development-profile', 'instance.write-development-url']);
    expect($agents->calls[0]['input'])->toBe(['application' => $application, 'owner' => 'orbit', 'entry_point' => 'artisan']);
    expect($agents->calls[1]['input']['directory'])->toBe($application)
        ->and($agents->calls[1]['input']['url'])->toBe('https://nested.test')
        ->and($agents->calls[1]['input']['require_artisan'])->toBeFalse();
    $site = new DevelopmentSite(1, '10.44.0.10', 'app-instance-1', $checkout, $root, $profile->phpVersion, 'nested.test');
    expect(new DevelopmentPhpFpmConfigRenderer()->render(collect([$site]), $account))->toContain("chdir = {$application}\n");
})->with(['nested Laravel' => ['server/web/public', '/server/web'], 'root public regression' => ['public', '']]);

it('maps unsafe nested application metadata to a source refusal', function (): void {
    [$configurator, $instance, , $agents] = nested_application_configurator('/home/orbit/acme', 'server/web/public');
    $agents->answer('instance.inspect-development-profile', ['kind' => 'unsafe']);

    expect(fn () => $configurator->inspect($instance))->toThrow(
        fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-dev.source_metadata_unsafe'),
    );
    expect($agents->commands())->toBe(['instance.inspect-development-profile']);
});

it('requires Artisan for another route directory and does not project the Instance key into it', function (): void {
    [$configurator, $instance, , $agents] = nested_application_configurator('/home/orbit/acme', 'public');
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'base64:instance-key']);
    $agents->answer('instance.write-development-url', ['written' => false]);

    $configurator->configureDirectoryUrl($instance, 'apps/docs', 'https://docs.acme.test');
    $configurator->configureDirectoryUrl($instance, 'apps/static', 'https://static.acme.test');

    expect($agents->commands())->toBe(['instance.write-development-url', 'instance.write-development-url']);
    foreach (['docs', 'static'] as $index => $name) {
        expect($agents->calls[$index]['input']['directory'])->toBe('/home/orbit/acme/apps/'.$name)
            ->and($agents->calls[$index]['input']['url'])->toBe('https://'.$name.'.acme.test')
            ->and($agents->calls[$index]['input']['require_artisan'])->toBeTrue()
            ->and($agents->calls[$index]['input']['app_key_stored'])->toBeFalse()
            ->and($agents->calls[$index]['input']['app_key'])->not->toContain('instance-key');
    }
});

/** @return array{RemoteDevelopmentInstanceConfigurator, Instance, ManagedUserAccount, FakeAgentCommandRunner} */
function nested_application_configurator(string $checkout, string $root): array
{
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $node = Node::query()->create(['name' => 'nested-source', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'user' => 'orbit']);
    $project = Project::query()->create(['name' => 'Nested', 'slug' => 'nested-'.Str::lower(Str::random(8)), 'repository_url' => 'https://example.test/nested.git', 'root' => $root]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => $checkout, 'branch' => 'feature', 'starting_commit' => str_repeat('a', 40), 'status' => 'source_resolved']);

    $agents = new FakeAgentCommandRunner;

    return [new RemoteDevelopmentInstanceConfigurator($agents, new ComposerSourceClassifier(new InstancePhpVersionCatalog)), $instance, $account, $agents];
}
