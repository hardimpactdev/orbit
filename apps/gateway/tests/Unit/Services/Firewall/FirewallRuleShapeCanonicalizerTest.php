<?php

declare(strict_types=1);

use App\Services\Firewall\FirewallRuleShapeCanonicalizer;
use Orbit\Core\Firewall\ManagedUfwComment;

it('shares managed UFW comment identity with the core producer', function (?string $reason, ?string $name): void {
    expect(FirewallRuleShapeCanonicalizer::managedComment($reason, $name))
        ->toBe(ManagedUfwComment::from($reason, $name));
})->with([
    'stored reason' => ['SSH from Main LAN', 'beast-main-lan-ssh'],
    'empty reason' => ['', 'private-api'],
    'null reason' => [null, 'private-api'],
    'empty reason and empty name' => ['', ''],
    'absent name' => [null, null],
]);

it('does not identify an observed rule without a managed comment identity', function (): void {
    expect(FirewallRuleShapeCanonicalizer::managedCommentIdentifiesObservedRule(
        null,
        null,
        ['comment' => null, 'port' => '8080'],
    ))->toBeFalse();
});
