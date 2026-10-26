<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\NativeRouteApplicationUrlInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

it('checks the selected application directories with one agent command', function (): void {
    $instance = route_application_url_instance('/srv/apps/shop/');
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-application-urls', ['matches' => [true, false]]);
    $expected = ['apps/docs' => 'https://docs.shop.test', 'apps/admin' => 'https://admin.shop.test'];

    $matches = route_application_url_inspector($agents)->inspect($instance, $expected);

    expect($matches)->toBe(['apps/docs' => true, 'apps/admin' => false]);
    expect($agents->calls)->toBe([[
        'node' => $instance->node->name,
        'command' => 'doctor.inspect-application-urls',
        'input' => ['checkout' => '/srv/apps/shop', 'applications' => [
            ['directory' => 'apps/docs', 'url' => 'https://docs.shop.test'],
            ['directory' => 'apps/admin', 'url' => 'https://admin.shop.test'],
        ]],
    ]]);
});

it('checks the checkout itself when a Route serves it', function (): void {
    $instance = route_application_url_instance('/srv/apps/shop');
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-application-urls', ['matches' => [false]]);

    expect(route_application_url_inspector($agents)->inspect($instance, ['' => 'https://root.shop.test']))->toBe(['' => false]);
    expect($agents->calls[0]['input']['applications'])->toBe([['directory' => '', 'url' => 'https://root.shop.test']]);
});

it('fails closed on an incomplete or malformed observation', function (array $output): void {
    $instance = route_application_url_instance('/srv/apps/shop');
    $agents = new FakeAgentCommandRunner()->answerMalformed('doctor.inspect-application-urls', $output);

    expect(fn () => route_application_url_inspector($agents)->inspect($instance, ['apps/docs' => 'https://docs.test']))
        ->toThrow(DoctorInspectionException::class);
})->with([
    'missing' => [[]],
    'short' => [['matches' => []]],
    'long' => [['matches' => [true, false]]],
    'wrong type' => [['matches' => ['yes']]],
    'not a list' => [['matches' => ['apps/docs' => true]]],
]);

it('maps an invalid directory refusal without exposing agent details', function (): void {
    $instance = route_application_url_instance('/srv/apps/shop');
    $agents = new FakeAgentCommandRunner()->refuse('doctor.inspect-application-urls', 'doctor.projection_invalid');

    expect(fn () => route_application_url_inspector($agents)->inspect($instance, ['../docs' => 'https://docs.test']))
        ->toThrow(DoctorInspectionException::class);
});

it('skips Node execution when no directory needs a check', function (): void {
    $agents = new FakeAgentCommandRunner;
    expect(route_application_url_inspector($agents)->inspect(new Instance, []))->toBe([]);
    expect($agents->calls)->toBe([]);
});

it('keeps application URL inspection behind agent commands', function (): void {
    $source = file_get_contents(app_path('Infrastructure/Doctor/NativeRouteApplicationUrlInspector.php'));
    expect($source)->not->toContain('SshExecutor', 'ProcessRunner', 'RemoteCommand', 'bash', '<<<');
});

function route_application_url_instance(string $checkout): Instance
{
    $project = Project::query()->create([
        'name' => 'Doctor App URL',
        'slug' => 'doctor-app-url-'.uniqid(),
        'repository_url' => 'https://git.example.test/acme/doctor-app-url.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'doctor-app-url-'.uniqid(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.9.'.random_int(2, 250),
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'development',
        'environment' => 'development',
        'checkout_path' => $checkout,
        'branch' => 'development',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ])->fresh(['node', 'project']);
}

function route_application_url_inspector(FakeAgentCommandRunner $agents): NativeRouteApplicationUrlInspector
{
    return new NativeRouteApplicationUrlInspector($agents, new CommandDeadline);
}
