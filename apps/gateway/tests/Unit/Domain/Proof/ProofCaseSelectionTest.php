<?php

declare(strict_types=1);

use App\Domain\Proof\ProofCaseSelection;

it('consumes the runner result covered by the shared CI fixtures without interpreting YAML', function (): void {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 6).'/tools/fixtures/proof-case-selection.json'), true, flags: JSON_THROW_ON_ERROR);
    $candidate = ['command' => 'demo:run', 'file' => 'swamp/proof/demo/run.cases.yaml'];
    foreach ($fixture['tests'] as $test) {
        $result = ['version' => 1, 'base' => 'base', 'head' => 'head', 'files' => [
            [...$candidate, 'only' => array_keys($test['expected']), 'reasons' => $test['expected']],
        ]];
        expect(ProofCaseSelection::select($result, 'base', 'head', $candidate))->toBe($test['expected'], $test['name']);
    }
});

it('selects whole files when the runner result is missing, stale or invalid', function (): void {
    $candidate = ['command' => 'demo:run', 'file' => 'swamp/proof/demo/run.cases.yaml'];
    $entry = [...$candidate, 'only' => ['a'], 'reasons' => ['a' => 'changed']];
    $valid = ['version' => 1, 'base' => 'base', 'head' => 'head', 'files' => [$entry]];
    foreach ([null, [], [...$valid, 'version' => 2], [...$valid, 'base' => 'old'], [...$valid, 'head' => 'old'],
        [...$valid, 'files' => []], [...$valid, 'files' => [$entry, $entry]],
        [...$valid, 'files' => [[...$entry, 'only' => null]]], [...$valid, 'files' => [[...$entry, 'reasons' => []]]],
    ] as $result) {
        expect(ProofCaseSelection::select($result, 'base', 'head', $candidate))->toBeNull();
    }
});
