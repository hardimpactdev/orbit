<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmImageInputs;

/** A release folder with one file at `$path`, as the digest reads it. */
function inputsRoot(string $path, string $content): string
{
    $root = sys_get_temp_dir().'/orbit-inputs-'.bin2hex(random_bytes(4));
    mkdir($root.'/'.dirname($path), 0700, true);
    file_put_contents($root.'/'.$path, $content);

    return $root;
}

it('answers the question of the digest for one path: the refresh trigger and the proof share one rule', function (string $path, bool $carried): void {
    $inputs = new TaskVmImageInputs;
    $one = inputsRoot($path, 'one');
    $two = inputsRoot($path, 'two');

    // A change of the file changes the digest exactly when the path is one the image carries.
    expect($inputs->carries($path))->toBe($carried)->and($inputs->digest($one) !== $inputs->digest($two))->toBe($carried);
    exec('rm -rf '.escapeshellarg($one).' '.escapeshellarg($two));
})->with([
    'a playbook' => ['swamp/ansible/tasks/task-vm-software.yml', true],
    'the folder of the ansible roles' => ['swamp/ansible/operation-node-add.yml', true],
    'a pin' => ['apps/gateway/resources/machine-software/recipe.json', true],
    'the root composer lock' => ['composer.lock', true],
    'the composer lock of an app' => ['apps/gateway/composer.lock', true],
    'the bun lock of an app' => ['apps/web/bun.lock', true],
    'the cargo lock' => ['Cargo.lock', true],
    'the cargo lock of an app' => ['apps/cli/Cargo.lock', true],
    'the bun binary lock of an app' => ['apps/web/bun.lockb', true],
    'the npm lock of an app' => ['apps/web/package-lock.json', true],
    'a lock of a package, which the image does not carry' => ['packages/php-sdk/composer.lock', false],
    'Gateway PHP' => ['apps/gateway/app/Domain/Proof/ProofRunIntent.php', false],
    'documents' => ['docs/reference/pipeline/3-proof.mmd', false],
    'a workflow' => ['swamp/workflows/workflow-operation-proof-run.yaml', false],
    'a lock file deeper than an app' => ['apps/gateway/vendor/composer.lock', false],
    'a compiled file of the playbooks' => ['swamp/ansible/__pycache__/x.pyc', false],
]);
