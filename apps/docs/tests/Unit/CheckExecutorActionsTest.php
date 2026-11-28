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
})->with(function (): array {
    // Every anchor comes from the files as they are, so a new action, row or list line never breaks a case.
    $root = dirname(__DIR__, 4);
    $source = (string) file_get_contents("$root/apps/agent/crates/executor-core/src/action.rs");
    $page = (string) file_get_contents("$root/docs/reference/executor.md");
    $predating = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents("$root/docs/reference/executor-actions-predating.txt"))), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
    preg_match('/ALL: \[Self; (\d+)\]/', $source, $all) || throw new RuntimeException('no ALL count in action.rs');
    $count = (int) $all[1];
    $countAs = static fn (int $n): string => "ALL: [Self; $n]";
    $names = substr($source, (int) strpos($source, 'pub fn name(self)'));
    preg_match_all('/Self::(\w+) => "([a-z0-9_.]+)",/', substr($names, 0, (int) strpos($names, "\n    }\n")), $arms, PREG_SET_ORDER);
    $arm = static fn (array $match): string => $match[0];
    // The catalogue rows: a row's first cell names its primitives, its third cell its state.
    $catalogue = substr($page, (int) strpos($page, "\n### The catalogue\n"));
    $catalogue = substr($catalogue, 0, (int) strpos($catalogue, "\n### ", 1));
    $rows = array_values(array_filter(explode("\n", $catalogue), static fn (string $line): bool => preg_match('/^\| `[a-z0-9_.]+`/', $line) === 1));
    $built = $planned = [];
    foreach ($rows as $row) {
        $cells = array_map('trim', explode('|', $row));
        preg_match_all('/`([a-z0-9_.]+)`/', $cells[1], $named);
        if (str_starts_with($cells[3], 'Built')) {
            $built = [...$built, ...$named[1]];
        } elseif (count($named[1]) === 1 && preg_match('/^(instance|route|process|schedule|task|project|node|orbit|t3)s?[._]/', $named[1][0]) !== 1) {
            $planned[$named[1][0]] ??= $row;
        }
    }
    // An arm of a built primitive, the insertion point of every new arm; and an arm whose action waits in the list.
    $builtArm = current(array_filter($arms, static fn (array $match): bool => in_array($match[2], $built, true))) ?: throw new RuntimeException('no built arm');
    // A listed action other than the list's first line, so its line is matched whole, between two line breaks.
    $listedArm = current(array_filter($arms, static fn (array $match): bool => in_array($match[2], array_slice($predating, 1), true))) ?: throw new RuntimeException('no listed arm');
    $anchor = $arm($builtArm);
    $plannedName = array_key_first($planned) ?? throw new RuntimeException('no planned row');
    $plannedRow = $planned[$plannedName];
    // The list's first line, matched whole after the comment above it.
    $firstLine = "\n{$predating[0]}\n";
    $after = static fn (string $added): array => ['/action.rs', $anchor, "$anchor $added"];

    return [
        'the tree as it is' => [[], 0, 'all executor actions ok'],
        'an arm wrapped over two lines' => [[['/action.rs', $anchor, "$anchor Self::DiskTrim =>\n                \"disk.trim\","]], 1, 'disk.trim: no catalogue row'],
        'an arm the parse cannot read' => [[['/action.rs', $countAs($count), $countAs($count + 1)]], 1, sprintf('Action::name has %d names, but ALL has %d actions', $count, $count + 1)],
        'a name with an upper-case letter' => [[['/action.rs', $anchor, str_replace("\"{$builtArm[2]}\"", '"'.ucfirst($builtArm[2]).'"', $anchor)]], 1, ucfirst($builtArm[2]).': an action name holds only lower-case letters'],
        'a concept with a plural or an underscore' => [[$after('Self::A => "instances.rename", Self::B => "route_publish",')], 1, 'route_publish: an action is not named after an Orbit concept'],
        'a list line that also has a row' => [[['/executor-actions-predating.txt', $firstLine, $firstLine.$builtArm[2]."\n"]], 1, "{$builtArm[2]} has a catalogue row; remove the line"],
        'an action and its line removed together' => [[
            ['/action.rs', $arm($listedArm), ''],
            ['/action.rs', $countAs($count), $countAs($count - 1)],
            ['/executor-actions-predating.txt', "\n{$listedArm[2]}\n", "\n"],
        ], 0, 'all executor actions ok'],
        'a new action without a row' => [[$after("Self::PlannedOne => \"$plannedName\",")], 1, "$plannedName: the catalogue in docs/reference/executor.md still says Planned"],
        'a new action that no row names' => [[$after('Self::DiskTrim => "disk.trim",')], 1, 'disk.trim: no catalogue row'],
        'a new action named after an Orbit concept' => [[$after('Self::InstanceRename => "instance.rename",')], 1, 'instance.rename: an action is not named after an Orbit concept'],
        'a new line in the list' => [[
            $after('Self::InstanceRename => "instance.rename",'),
            ['/executor-actions-predating.txt', $firstLine, $firstLine."instance.rename\n"],
        ], 1, 'instance.rename is new; the list may only shrink'],
        'a line for a deleted action' => [[['/action.rs', $arm($listedArm), '']], 1, "{$listedArm[2]} is no action; remove the line"],
        'a planned row marked built' => [[
            $after("Self::PlannedOne => \"$plannedName\","),
            ['/action.rs', $countAs($count), $countAs($count + 1)],
            ['/executor.md', $plannedRow, (string) preg_replace('/\| Planned([^|]*)\|/', '| Built$1|', $plannedRow, 1)],
        ], 0, 'all executor actions ok'],
    ];
});
