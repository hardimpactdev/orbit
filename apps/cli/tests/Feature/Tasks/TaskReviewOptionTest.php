<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;

it('refuses a review mode other than manual or automatic before it reaches the Gateway', function (): void {
    $mock = MockClient::global([]);

    expect(Artisan::call('task:create', ['title' => 'T', '--project' => '1', '--brief' => 'B', '--review' => 'both', '--json' => true, '--no-interaction' => true]))->toBe(1);

    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('task.review_invalid');
    $mock->assertNothingSent();
});
