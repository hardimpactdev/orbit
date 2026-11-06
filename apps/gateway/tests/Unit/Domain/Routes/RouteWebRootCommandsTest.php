<?php

declare(strict_types=1);

use App\Domain\Routes\RouteWebRootCommands;
use App\Models\Instance;

$access = static fn (array $sites): array => ['user' => 'nckrtl', 'sites' => $sites];
$site = static fn (string $checkout, string $root = 'public'): array => ['checkout' => $checkout, 'relative_root' => $root, 'application' => $checkout];

it('grants a Laravel Instance in one command per kind', function () use ($access, $site): void {
    $rows = RouteWebRootCommands::access(9, $access([$site('/fast/apps/shop/main')]), new Instance(['source_is_laravel' => true]));

    expect(array_column($rows, 'node_id'))->toBe([9, 9, 9, 9, 9]);
    $argv = array_column($rows, 'argv');
    // Deny Caddy the checkout: default entries on the directories, then the entry on everything.
    expect($argv[0])->toContain('d:u:caddy:---', '/fast/apps/shop/main')
        ->and($argv[1])->toContain('u:caddy:---', '-R', '/fast/apps/shop/main');
    // Passage: every directory above both served paths, once, in one command.
    expect($argv[2])->toBe(['/usr/bin/setfacl', '-m', 'u:caddy:--x', '--', '/fast/apps/shop/main', '/fast/apps/shop', '/fast/apps', '/fast', '/fast/apps/shop/main/storage/app', '/fast/apps/shop/main/storage']);
    // Read on the public root and storage/app/public, each as one command naming both.
    expect($argv[3])->toContain('d:u:caddy:r-x')->and(array_slice($argv[3], 9, 3))->toBe(['/fast/apps/shop/main/public', '/fast/apps/shop/main/storage/app/public', '-type']);
    expect($argv[4])->toContain('u:caddy:r-X', '/fast/apps/shop/main/public', '/fast/apps/shop/main/storage/app/public');
});

it('grants only the served root of an Instance that is not Laravel', function () use ($access, $site): void {
    $rows = RouteWebRootCommands::access(9, $access([$site('/fast/apps/site', 'dist')]), new Instance(['source_is_laravel' => false]));

    expect($rows)->toHaveCount(5)
        ->and(array_column($rows, 'argv')[4])->toContain('/fast/apps/site/dist')->not->toContain('/fast/apps/site/storage/app/public');
});

it('denies each checkout once and grants every site in the same three commands', function () use ($access, $site): void {
    $rows = RouteWebRootCommands::access(9, $access([$site('/fast/apps/a'), $site('/fast/apps/b')]), new Instance(['source_is_laravel' => false]));

    $argv = array_column($rows, 'argv');
    expect($argv[0])->toContain('/fast/apps/a')
        ->and($argv[2])->toContain('/fast/apps/b')
        ->and($argv[4])->toBe(['/usr/bin/setfacl', '-m', 'u:caddy:--x', '--', '/fast/apps/a', '/fast/apps', '/fast', '/fast/apps/b']);
    expect($rows)->toHaveCount(7)
        ->and(array_column($rows, 'argv')[5])->toContain('/fast/apps/a/public', '/fast/apps/b/public')
        ->and(array_column($rows, 'argv')[6])->toContain('/fast/apps/a/public', '/fast/apps/b/public');
});

it('names no command for an Instance without a served site', function () use ($access): void {
    expect(RouteWebRootCommands::access(9, $access([]), new Instance(['source_is_laravel' => true])))->toBe([]);
});
