<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('preserves the required Instance option failure bytes for each family', function (string $command, array $arguments, string $code, ?string $selector, bool $json): void {
    $mock = MockClient::global([]);
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);
    $arguments['--json'] = $json;
    if ($selector !== null) {
        $arguments['--instance'] = $selector;
    }

    expect($tester->execute($arguments, ['interactive' => false, 'decorated' => false]))->toBe(1);

    $message = 'Instance ID or Route domain is required.';
    $expected = $json
        ? json_encode(['error' => ['code' => $code, 'message' => $message, 'request_id' => null]], JSON_THROW_ON_ERROR)."\n"
        : $message."\n";

    expect($tester->getDisplay(true))->toBe($expected)
        ->and($mock->getLastPendingRequest())->toBeNull();
})->with([
    'database add' => ['instance:database:add', ['slug' => 'app'], 'database.instance_required'],
    'database remove' => ['instance:database:remove', ['slug' => 'app'], 'database.instance_required'],
    'websocket add' => ['instance:websocket:add', ['app' => 'hauzer'], 'websocket.instance_required'],
    'websocket remove' => ['instance:websocket:remove', ['app' => 'hauzer'], 'websocket.instance_required'],
    'environment import' => ['env:import', [], 'env.instance_required'],
    'environment update' => ['env:update', [], 'env.instance_required'],
    'environment sync' => ['env:sync', [], 'env.instance_required'],
])->with(['omitted' => null, 'empty' => ''])->with(['human' => false, 'json' => true]);

it('returns only the first attachment validation failure as one JSON document', function (string $command, array $arguments, string $code, string $message): void {
    $mock = MockClient::global([]);
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    expect($tester->execute([...$arguments, '--json' => true], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay(true))->toBe(json_encode([
            'error' => ['code' => $code, 'message' => $message, 'request_id' => null],
        ], JSON_THROW_ON_ERROR)."\n")
        ->and($mock->getLastPendingRequest())->toBeNull();
})->with([
    'empty websocket add' => ['instance:websocket:add', ['app' => ''], 'websocket.name_required', 'WebSocket app name is required.'],
    'empty websocket remove' => ['instance:websocket:remove', ['app' => ''], 'websocket.name_required', 'WebSocket app name is required.'],
    'empty database add' => ['instance:database:add', ['slug' => ''], 'database.slug_required', 'Database connection slug is required.'],
    'empty database remove' => ['instance:database:remove', ['slug' => ''], 'database.slug_required', 'Database connection slug is required.'],
    'invalid websocket add' => ['instance:websocket:add', ['app' => 'Not-a-slug'], 'websocket.name_invalid', 'WebSocket app name is invalid.'],
    'invalid websocket remove' => ['instance:websocket:remove', ['app' => 'Not-a-slug'], 'websocket.name_invalid', 'WebSocket app name is invalid.'],
    'invalid database add' => ['instance:database:add', ['slug' => 'Not-a-slug'], 'database.slug_invalid', 'Database connection slug is invalid.'],
    'invalid database remove' => ['instance:database:remove', ['slug' => 'Not-a-slug'], 'database.slug_invalid', 'Database connection slug is invalid.'],
    'missing Instance before invalid add prefix' => ['instance:database:add', ['slug' => 'app', '--prefix' => 'db'], 'database.instance_required', 'Instance ID or Route domain is required.'],
    'missing Instance before invalid remove prefix' => ['instance:database:remove', ['slug' => 'app', '--prefix' => 'db'], 'database.instance_required', 'Instance ID or Route domain is required.'],
]);
