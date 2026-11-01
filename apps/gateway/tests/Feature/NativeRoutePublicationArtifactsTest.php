<?php

declare(strict_types=1);

use App\Domain\Routes\NativeRoutePublicationArtifacts;

it('captures only affected entries and shares equal artifacts across phases', function (): void {
    $header = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
    $moving = "# orbit: routes moving\nhttps://moving.test { reverse_proxy 10.44.0.9 }\n";
    $survivor = "# orbit: routes survivor\nhttps://survivor.test { reverse_proxy 10.44.0.10 }\n";
    $pool = static fn (string $name): string => "[orbit-instance-{$name}]\nchdir = /home/orbit/{$name}\npm.max_children = 5\n";
    $snapshot = static fn (bool $present): array => [
        'caddy' => ['content' => $header."\n".($present ? $moving."\n" : '').$survivor, 'version' => str_repeat('a', 32), 'listen_addresses' => ['10.44.0.9'], 'unserved' => false],
        'php' => ['account_user' => 'orbit', 'configurations' => (object) ['8.4' => "; Managed\n".($present ? $pool('1') : '').$pool('2')]],
        'dns' => ['node_id' => 1, 'records' => "# Managed by Orbit.\n".($present ? "host-record=moving.test,10.44.0.9\n" : '')."host-record=survivor.test,10.44.0.10\n", 'catalog' => json_encode(['version' => 1, 'domains' => (object) [...($present ? ['moving.test' => ['10.44.0.9']] : []), 'survivor.test' => ['10.44.0.10']]], JSON_THROW_ON_ERROR), 'digest' => 'old'],
        'certificate_scopes' => ['moving', 'survivor'],
    ];
    $before = $snapshot(true);
    $after = $snapshot(false);

    $compact = app(NativeRoutePublicationArtifacts::class)->capture([['node_id' => 9, 'before' => $before, 'prepared' => $before, 'withdrawn' => $after, 'after' => $after]]);

    $part = $compact['publications'][0];
    expect($part['before'])->toBe($part['prepared'])->and($part['after'])->toBe($part['withdrawn'])
        ->and($compact['artifacts']['caddy'])->toHaveCount(2)->and($compact['artifacts']['php'])->toHaveCount(2)->and($compact['artifacts']['dns'])->toHaveCount(2);
    $resolved = NativeRoutePublicationArtifacts::resolve($compact['artifacts'], $part);
    expect($resolved['before']['caddy']['content'])->toBe($header."\n".$moving)
        ->and($resolved['after']['caddy']['content'])->toBe($header)
        ->and($resolved['before']['caddy'])->not->toHaveKey('blocks')
        ->and($resolved['before']['php']['configurations'])->toEqual((object) ['8.4' => "; Managed\n".$pool('1')])
        ->and($resolved['after']['php']['configurations'])->toEqual((object) [])
        ->and($resolved['before']['dns']['records'])->toContain('host-record=moving.test')->not->toContain('survivor')
        ->and(json_decode($resolved['before']['dns']['catalog'], true))->toBe(['domains' => ['moving.test' => ['10.44.0.9']]])
        ->and(json_decode($resolved['after']['dns']['catalog'], true))->toBe(['domains' => []]);
    expect($resolved['before']['caddy']['version'])->toBe(substr(hash('sha256', $resolved['before']['caddy']['content']), 0, 32));
});

it('retains every distinct intermediate state and repeated same-owner Caddy block', function (): void {
    $header = "# Managed by Orbit: Node Caddy build\n";
    $site = static fn (string $name, string $body): string => "# orbit: routes {$name}\n{$body}\n";
    $snapshot = static fn (string $phase): array => ['caddy' => ['content' => $header."\n".$site('moving', $phase)."\n".$site('moving', 'same')."\n".$site('untouched', 'keep'), 'version' => str_repeat('b', 32), 'listen_addresses' => [], 'unserved' => false], 'php' => ['account_user' => 'orbit', 'configurations' => (object) []], 'dns' => null];
    $publication = ['node_id' => 9];
    foreach (['before', 'prepared', 'withdrawn', 'after'] as $name) {
        $publication[$name] = $snapshot($name);
    }
    $compact = app(NativeRoutePublicationArtifacts::class)->capture([$publication]);
    expect($compact['artifacts']['caddy'])->toHaveCount(4)->and($compact['artifacts']['php'])->toHaveCount(1)->and($compact['artifacts']['dns'])->toBe([]);
    $resolved = NativeRoutePublicationArtifacts::resolve($compact['artifacts'], $compact['publications'][0]);
    foreach (['before', 'prepared', 'withdrawn', 'after'] as $phase) {
        expect($resolved[$phase]['caddy']['content'])->toContain($site('moving', $phase), $site('moving', 'same'))->not->toContain('untouched')
            ->and($resolved[$phase]['dns'])->toBeNull();
    }
});
