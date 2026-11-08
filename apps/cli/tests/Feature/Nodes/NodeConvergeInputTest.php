<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;

it('refuses invalid converge inputs without contacting the Gateway', function (array $arguments, string $code): void {
    $mock = MockClient::global([]);
    try {
        $this->artisan('node:converge', [...$arguments, '--json' => true])
            ->expectsOutputToContain($code)->assertExitCode(1);
        $mock->assertNothingSent();
    } finally {
        MockClient::destroyGlobal();
    }
})->with([
    'missing' => [[], 'node.id_invalid'],
    'zero' => [['node' => '0'], 'node.id_invalid'],
    'negative' => [['node' => '-1'], 'node.id_invalid'],
    'name' => [['node' => 'app-dev'], 'node.id_invalid'],
    'option-like' => [['node' => '--bad'], 'node.id_invalid'],
    'overflow' => [['node' => '9999999999999999999999999999'], 'node.id_invalid'],
    'exclusive' => [['node' => '1', '--base' => true, '--roles' => 'app-dev'], 'validation.failed'],
    'empty' => [['node' => '1', '--roles' => ''], 'validation.failed'],
    'unknown' => [['node' => '1', '--roles' => 'not-a-role'], 'validation.failed'],
]);
