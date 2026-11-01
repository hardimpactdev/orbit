<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use Tests\TestCase;

uses(TestCase::class);

it('renders the label as a printable ufw comment of at most 64 characters', function (?string $label, string $comment): void {
    expect(new FirewallRule(['label' => $label])->comment())->toBe($comment);
})->with([
    'no label' => [null, 'orbit'],
    'blank label' => ["  \t ", 'orbit'],
    'plain label' => ['Postgres replicas', 'orbit Postgres replicas'],
    'quotes and control characters' => ["it's \"db\"\x07 \u{00e9}tag", 'orbit its db tag'],
    'collapsed whitespace' => ["office\n\n  vpn", 'orbit office vpn'],
    'long label' => [str_repeat('a', 70), 'orbit '.str_repeat('a', 64)],
]);

it('names its identity and expands any into both ufw protocols', function (string $protocol, array $protocols): void {
    $rule = new FirewallRule(['port' => '8000:8010', 'protocol' => $protocol, 'source' => '192.0.2.0/24']);

    expect($rule->identity())->toBe("8000:8010/{$protocol} from 192.0.2.0/24")->and($rule->protocols())->toBe($protocols);
})->with([['any', ['tcp', 'udp']], ['tcp', ['tcp']], ['udp', ['udp']]]);
