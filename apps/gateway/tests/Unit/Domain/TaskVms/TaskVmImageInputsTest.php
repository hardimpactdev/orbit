<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmImageInputs;

it('says whether the image carries a path, the rule the proof asks the ready copies for', function (string $path, bool $carried): void {
    expect((new TaskVmImageInputs)->carries($path))->toBe($carried);
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
