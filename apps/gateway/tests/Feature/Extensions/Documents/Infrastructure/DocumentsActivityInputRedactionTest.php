<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Route;
use Orbit\Extensions\Documents\Infrastructure\DocumentsActivityInputRedaction;

it('decides the input of every Project Document command', function (): void {
    $commands = collect(Route::getRoutes()->getRoutesByName())->keys()
        ->filter(static fn (string $name): bool => str_starts_with($name, 'project:document:'))
        ->sort()->values()->all();

    expect($commands)->not->toBeEmpty()
        ->and(collect((new DocumentsActivityInputRedaction)->commands())->sort()->values()->all())->toBe($commands);
});

it('keeps nothing and never parses the request input', function (string $command): void {
    $input = static fn (): array => throw new LogicException('The document body was parsed.');

    expect((new DocumentsActivityInputRedaction)->input($command, $input))->toBe([]);
})->with((new DocumentsActivityInputRedaction)->commands());

it('records empty input for a Project Document command while the switch is off', function (): void {
    app(ExtensionStore::class)->set('documents', false);
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'documents-redaction-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $project = Project::query()->create(['name' => 'Documents', 'slug' => 'documents', 'repository_url' => 'https://example.test/documents.git']);

    $this->postJson('/api/v1/projects/'.$project->id.'/documents', ['kind' => 'file', 'name' => 'note.txt', 'content_text' => 'private draft'])
        ->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');

    $activity = Activity::query()->where('command', 'project:document:create')->sole();
    expect($activity->properties?->toArray()['input'])->toBe([])
        ->and(json_encode($activity->properties))->not->toContain('private draft');
});
