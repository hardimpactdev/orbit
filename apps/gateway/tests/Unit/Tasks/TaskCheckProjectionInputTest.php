<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckProjection;
use App\Models\Operation;

it('supplies the base-check defaults required by native command guards', function (array $options, bool $failsOnBase, array $paths): void {
    $command = ['id' => 'docs', 'type' => 'command', 'command' => 'composer docs-lint', 'directory' => '.', ...$options];
    $operation = new Operation(['input' => [
        'deliverables' => [['id' => 'review', 'type' => 'review'], $command],
        'execution_secrets' => [],
    ]]);

    $input = (new TaskCheckProjection)->input($operation);

    expect($input['intent']['command_deliverables'])->toHaveCount(1);
    expect($input['intent']['command_deliverables'][0])->toMatchArray([
        'id' => 'docs', 'fails_on_base' => $failsOnBase, 'paths' => $paths,
    ]);
    expect($operation->input['deliverables'][1])->toBe($command);
})->with([
    'ordinary command' => [[], false, []],
    'regression command' => [['fails_on_base' => true, 'paths' => ['tests/example.php']], true, ['tests/example.php']],
]);
