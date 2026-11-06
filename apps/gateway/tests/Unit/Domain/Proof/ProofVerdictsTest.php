<?php

declare(strict_types=1);

use App\Domain\Proof\ProofVerdicts;

it('reads one row for each verdict line, and one unrun row for a report with none', function (): void {
    $rows = ProofVerdicts::rows('firewall:allow', 'swamp/proof/firewall/allow.cases.yaml', "{\"id\": \"main\", \"verdict\": \"passed\", \"secs\": 2}\nnoise\n{\"id\": \"b\", \"verdict\": \"unrun\", \"cause\": \"infrastructure\", \"secs\": 0}");

    expect($rows['ran'])->toBeTrue()
        ->and(array_column($rows['cases'], 'program'))->toBe(['swamp/proof/firewall/allow.cases.yaml#main', 'swamp/proof/firewall/allow.cases.yaml#b'])
        ->and($rows['cases'][1])->toMatchArray(['command' => 'firewall:allow', 'case' => 'b', 'verdict' => 'unrun', 'cause' => 'infrastructure']);

    $none = ProofVerdicts::rows('firewall:allow', 'f.yaml', '');
    expect($none['ran'])->toBeFalse()->and($none['cases'])->toBe([['program' => 'f.yaml', 'command' => 'firewall:allow', 'verdict' => 'unrun', 'seconds' => 0]]);
});

it('passes only a list that is not empty and holds nothing but passed cases', function (): void {
    expect(ProofVerdicts::passed([]))->toBeFalse()
        ->and(ProofVerdicts::passed([['verdict' => 'passed'], ['verdict' => 'unrun']]))->toBeFalse()
        ->and(ProofVerdicts::passed([['verdict' => 'passed']]))->toBeTrue();
});
