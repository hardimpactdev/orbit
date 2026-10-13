<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;

function fullRecipe(): TopologyRecipe
{
    return TopologyRecipe::fromOption('app-dev,app-prod,app-prod-2');
}

describe('TopologyTarget', function () {
    it('maps topology slots and Node addresses to deterministic IPv4 addresses', function () {
        expect(TopologyTarget::ipv4For(1, 10))->toBe('10.232.1.10')
            ->and(TopologyTarget::ipv4For(200, 11))->toBe('10.232.200.11')
            ->and(TopologyTarget::ipv4For(100, 14))->toBe('10.232.100.14');
    });

    it('rejects invalid topology slots and addresses', function (int $slot, int $address) {
        expect(fn () => TopologyTarget::ipv4For($slot, $address))->toThrow(InvalidArgumentException::class);
    })->with([
        'slot below range' => [0, 10],
        'slot above range' => [201, 10],
        'address below range' => [1, 9],
    ]);

    it('rejects an invalid issue identity', function (string $issue) {
        expect(fn () => TopologyTarget::for($issue, AttemptId::generate(), fullRecipe()))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'lowercase prefix' => ['tst-123'],
        'no number' => ['TST-'],
        'leading zero' => ['TST-0'],
    ]);

    it('uses compact deterministic network names within the managed bridge limit', function () {
        $attempt = new AttemptId(str_repeat('a', 32));

        expect(TopologyTarget::for('TST-123', $attempt, fullRecipe())->network())->toBe('oe-50fa1830b7de')
            ->and(strlen(TopologyTarget::for('ORBIT-123456789', $attempt, fullRecipe())->network()))->toBe(15);
    });

    it('names one guest per declared Node and refuses an undeclared one', function () {
        $target = TopologyTarget::for('TST-123', new AttemptId(str_repeat('a', 32)), TopologyRecipe::compose(['app-dev']));

        expect($target->instances())->toBe([
            'gateway' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
            'app-dev' => 'orbit-e2e-tst-123-aaaaaaaa-app-dev',
            'operator' => 'orbit-e2e-tst-123-aaaaaaaa-operator',
        ])
            ->and(strlen(TopologyTarget::for('ORBITABCDE-123456789', new AttemptId(str_repeat('a', 32)), fullRecipe())->instance('app-prod-2')))
            ->toBeLessThanOrEqual(63)
            ->and(fn () => $target->instance('app-prod'))
            ->toThrow(InvalidArgumentException::class, 'The topology has no Node [app-prod].');
    });

    it('never shares resource identities between two attempts of one issue', function () {
        $first = TopologyTarget::for('TST-123', new AttemptId(str_repeat('a', 32)), fullRecipe());
        $second = TopologyTarget::for('TST-123', new AttemptId(str_repeat('b', 32)), fullRecipe());

        expect($first->network())->not->toBe($second->network())
            ->and($first->instance('gateway'))->not->toBe($second->instance('gateway'))
            ->and($first->mac('gateway'))->not->toBe($second->mac('gateway'));
    });

    it('uses the deterministic network and Node MAC formula', function () {
        $target = TopologyTarget::for('TST-123', new AttemptId(str_repeat('a', 32)), fullRecipe());

        expect($target->mac('gateway'))
            ->toBe('00:16:3e:'.implode(':', str_split(substr(sha1($target->network().':gateway'), 0, 6), 2)));
    });

    it('matches the issue as one delimited branch token', function (string $branch, bool $matches) {
        expect(TopologyTarget::for('TST-123', AttemptId::generate(), fullRecipe())->matchesBranch($branch))->toBe($matches);
    })->with([
        'prefixed feature branch' => ['feature/TST-123-build-topology', true],
        'lowercase worktree branch' => ['codex/tst-123', true],
        'larger issue number' => ['feature/TST-1234-build-topology', false],
        'issue-like suffix' => ['feature/TST-123A-build-topology', false],
        'unrelated issue' => ['feature/TST-124-build-topology', false],
    ]);
});
