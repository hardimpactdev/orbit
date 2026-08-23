<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Firewall\FirewallTargetPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('keeps SQL and PHP Ubuntu firewall eligibility in parity', function (mixed $platform, bool $eligible): void {
    expect(FirewallTargetPlatform::isUbuntu($platform))->toBe($eligible);

    $node = Node::factory()->create(['platform' => $platform]);
    $matched = Node::query()
        ->whereKey($node->id)
        ->where(function ($query): void {
            FirewallTargetPlatform::constrainUbuntu($query);
        })
        ->exists();

    expect($matched)->toBe($eligible);
})->with([
    'exact ubuntu' => ['ubuntu', true],
    'ubuntu_24-04' => ['ubuntu_24-04', true],
    'ubuntu_26-04' => ['ubuntu_26-04', true],
    'ubuntu_24-04-LTS' => ['ubuntu_24-04-LTS', true],
    'ubuntu_Noble' => ['ubuntu_Noble', true],
    'hyphenated ubuntu-24-04' => ['ubuntu-24-04', false],
    'ubuntufoo' => ['ubuntufoo', false],
    'debian_12' => ['debian_12', false],
    'empty' => ['', false],
    'null' => [null, false],
    'Ubuntu_24-04' => ['Ubuntu_24-04', false],
    'UBUNTU_24-04' => ['UBUNTU_24-04', false],
    'Ubuntu' => ['Ubuntu', false],
    'UBUNTU' => ['UBUNTU', false],
]);
