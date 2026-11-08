<?php

declare(strict_types=1);

use App\Domain\Proof\ProofCaseSelection;
use App\Domain\Shared\ResourceOperationException;

it('selects the same case ids and reasons as the CI planner', function (): void {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 6).'/tools/fixtures/proof-case-selection.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($fixture['tests'] as $test) {
        $before = ($test['new_file'] ?? false) ? null : array_replace($fixture['base'], $test['before'] ?? []);
        $after = array_replace($fixture['base'], $test['after']);
        expect(ProofCaseSelection::select($before, $after, $test['code_changed'] ?? false, $test['before_batches'] ?? [], $test['after_batches'] ?? []))
            ->toEqual($test['expected'], $test['name']);
    }
});

it('refuses malformed or empty selected cases', function (array $after): void {
    expect(fn () => ProofCaseSelection::select(null, $after))->toThrow(ResourceOperationException::class);
})->with([[[]], [['cases' => []]], [['cases' => [['id' => 'same'], ['id' => 'same']]]]]);
