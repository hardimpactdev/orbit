<?php

declare(strict_types=1);

use App\Domain\Proof\ProofSeeds;
use Symfony\Component\Yaml\Yaml;

it('bounds each push and each target\'s copy step by the numbers that the seed copy budget counts', function (): void {
    // docs/reference/operations.md: the run budget counts ProofSeeds::CopySeconds per target, so the step must stop by then.
    $swamp = dirname(base_path(), 2).'/swamp';
    $steps = collect(Yaml::parseFile($swamp.'/workflows/workflow-operation-instance-seed-copy.yaml')['jobs'][0]['steps'])->keyBy('name');
    $copy = $steps['Copy the seed'];
    $playbook = (string) file_get_contents($swamp.'/ansible/proof-seed-copy.yml');
    preg_match_all("/\\['timeout', '([0-9]+)', 'incus', /", $playbook, $pushes);

    expect($copy['task']['inputs']['run'])->toStartWith('timeout '.ProofSeeds::CopySeconds.' ')
        ->and($copy)->not->toHaveKey('concurrency')
        ->and($pushes[1])->toBe([(string) ProofSeeds::PushSeconds, (string) ProofSeeds::PushSeconds])
        ->and(ProofSeeds::CopySeconds)->toBeGreaterThan(2 * ProofSeeds::PushSeconds);
});
