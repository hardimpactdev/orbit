<?php

declare(strict_types=1);

use Orbit\Core\Firewall\ManagedUfwComment;

it('uses a non-empty stored reason as the managed UFW comment', function (): void {
    expect(ManagedUfwComment::from('SSH from Main LAN', 'beast-main-lan-ssh'))
        ->toBe('SSH from Main LAN');
});

it('falls back to orbit:<name> when the stored reason is empty', function (?string $reason): void {
    expect(ManagedUfwComment::from($reason, 'private-api'))
        ->toBe('orbit:private-api');
})->with([
    'null reason' => [null],
    'empty reason' => [''],
]);

it('does not invent a comment when reason and name are both absent', function (?string $reason, ?string $name): void {
    expect(ManagedUfwComment::from($reason, $name))->toBeNull();
})->with([
    'empty reason and empty name' => ['', ''],
    'null reason and null name' => [null, null],
    'empty reason and null name' => ['', null],
    'null reason and empty name' => [null, ''],
]);
