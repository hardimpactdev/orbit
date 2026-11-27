<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps every executor action to a built catalogue row or a line of the shrinking list', function (array $patches, int $exitCode, string $message): void {
    $process = new Process(['node', '-', dirname(__DIR__, 4), json_encode($patches, JSON_THROW_ON_ERROR)], env: ['BASE' => 'base']);
    // The base commit's list is the list on disk, read before any patch.
    $process->setInput(<<<'JS'
const fs = require('node:fs')
const child = require('node:child_process')
const read = fs.readFileSync
const patches = JSON.parse(process.argv[3])
const before = read(process.argv[2] + '/docs/reference/executor-actions-predating.txt', 'utf8')
child.execFileSync = (command, args) => args[0] === 'show' ? before : ''
fs.readFileSync = function (path, ...args) {
  let text = read.call(this, path, ...args)
  for (const [file, search, replace] of patches) if (String(path).endsWith(file)) text = text.replace(search, replace)
  return text
}
require(process.argv[2] + '/bin/check-executor-actions')
JS);
    $process->run();

    expect($process->getExitCode())->toBe($exitCode, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain($message);
})->with([
    'the tree as it is' => [[], 0, 'all executor actions ok'],
    'an arm wrapped over two lines' => [[['/action.rs', 'Self::ProofNoop => "proof.noop",', "Self::ProofNoop => \"proof.noop\", Self::DiskTrim =>\n                \"disk.trim\","]],
        1, 'disk.trim: no catalogue row'],
    'an arm the parse cannot read' => [[['/action.rs', 'ALL: [Self; 29]', 'ALL: [Self; 30]']], 1, 'Action::name has 29 names, but ALL has 30 actions'],
    'a name with an upper-case letter' => [[['/action.rs', '"proof.noop"', '"Proof.noop"']], 1, 'Proof.noop: an action name holds only lower-case letters'],
    'a concept with a plural or an underscore' => [[['/action.rs', 'Self::ProofNoop => "proof.noop",', 'Self::ProofNoop => "proof.noop", Self::A => "instances.rename", Self::B => "route_publish",']],
        1, 'route_publish: an action is not named after an Orbit concept'],
    'a list line that also has a row' => [[['/executor-actions-predating.txt', "instance.clone\n", "instance.clone\nproof.noop\n"]], 1, 'proof.noop has a catalogue row; remove the line'],
    'an action and its line removed together' => [[
        ['/action.rs', 'Self::InstanceStop => "instance.stop",', ''],
        ['/action.rs', 'ALL: [Self; 29]', 'ALL: [Self; 28]'],
        ['/executor-actions-predating.txt', "instance.stop\n", ''],
    ], 0, 'all executor actions ok'],
    'a new action without a row' => [[['/action.rs', 'Self::ProofNoop => "proof.noop",', 'Self::ProofNoop => "proof.noop", Self::FileEnsure => "file.ensure",']],
        1, 'file.ensure: the catalogue in docs/reference/executor.md still says Planned'],
    'a new action that no row names' => [[['/action.rs', 'Self::ProofNoop => "proof.noop",', 'Self::ProofNoop => "proof.noop", Self::DiskTrim => "disk.trim",']],
        1, 'disk.trim: no catalogue row'],
    'a new action named after an Orbit concept' => [[['/action.rs', 'Self::ProofNoop => "proof.noop",', 'Self::ProofNoop => "proof.noop", Self::InstanceRename => "instance.rename",']],
        1, 'instance.rename: an action is not named after an Orbit concept'],
    'a new line in the list' => [[
        ['/action.rs', 'Self::ProofNoop => "proof.noop",', 'Self::ProofNoop => "proof.noop", Self::InstanceRename => "instance.rename",'],
        ['/executor-actions-predating.txt', "instance.clone\n", "instance.clone\ninstance.rename\n"],
    ], 1, 'instance.rename is new; the list may only shrink'],
    'a line for a deleted action' => [[['/action.rs', 'Self::InstanceStop => "instance.stop",', '']], 1, 'instance.stop is no action; remove the line'],
    'a planned row marked built' => [[
        ['/action.rs', 'Self::ProofNoop => "proof.noop",', 'Self::ProofNoop => "proof.noop", Self::FileRead => "file.read",'],
        ['/action.rs', 'ALL: [Self; 29]', 'ALL: [Self; 30]'],
        ['/executor.md', '| `file.read` | The content of one small file, read only | Planned |', '| `file.read` | The content of one small file, read only | Built |'],
    ], 0, 'all executor actions ok'],
]);
