<?php

declare(strict_types=1);

use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofSelection;
use App\Domain\Proof\ProofSelectionList;
use App\Domain\Shared\ResourceOperationException;

const PROOF_LIST = <<<'LIST'
# path glob                                   selects

docs/**                                       skip
**/*.md                                       skip
apps/**                                       none: no case yet
apps/web/**                                   skip
swamp/workflows/workflow-firewall-*.yaml      firewall:allow firewall:deny firewall:remove
swamp/proof/firewall/*.cases.yaml             firewall:allow firewall:deny firewall:remove
swamp/proof/reset/**                          *
bin/proof-fleet                               *
swamp/workflows/workflow-vault-sync.yaml      none: no case yet
swamp/workflows/workflow-shared-*.yaml        node:role:add firewall:allow
swamp/workflows/workflow-ghost.yaml           ghost:run
LIST;

const PROOF_CASES_FILES = [
    'swamp/proof/firewall/allow.cases.yaml',
    'swamp/proof/firewall/deny.cases.yaml',
    'swamp/proof/node/role-add.cases.yaml',
];

/** @param  list<string|array{0:string,1:string}|array{0:string,1:string,2:string}>  $files */
function proofChanges(array $files, bool $complete = true): ProofChangedFiles
{
    return new ProofChangedFiles(str_repeat('a', 40), array_map(static fn (string|array $file): ProofChangedFile => is_string($file)
        ? new ProofChangedFile($file, 'modified') : new ProofChangedFile($file[0], $file[1], $file[2] ?? null), $files), $complete);
}

function proofSelect(array $files, bool $all = false, bool $complete = true, ?string $list = PROOF_LIST): array
{
    $result = ProofSelection::select($list, proofChanges($files, $complete), $all, PROOF_CASES_FILES);

    return ['files' => $result->files(), 'cases' => $result->cases, 'uncovered' => $result->uncovered, 'because' => $result->everythingBecause, 'nothing' => $result->nothingToProve()];
}

function proofRefusal(callable $callback): ResourceOperationException
{
    try {
        $callback();
    } catch (ResourceOperationException $exception) {
        return $exception;
    }
    throw new LogicException('No refusal.');
}

it('skips documentation, charts and case descriptions: nothing to prove', function (): void {
    $selected = proofSelect(['docs/cli/proof/run.mdx', 'README.md', 'docs/cli/firewall/allow.mmd', 'apps/web/src/main.ts']);

    expect($selected['nothing'])->toBeTrue()->and($selected['cases'])->toBe([])->and($selected['uncovered'])->toBe([]);
});

it('lists a path marked as having no case yet as uncovered and proves nothing', function (): void {
    $selected = proofSelect(['swamp/workflows/workflow-vault-sync.yaml']);

    expect($selected['nothing'])->toBeTrue()
        ->and($selected['uncovered'])->toBe([['path' => 'swamp/workflows/workflow-vault-sync.yaml', 'reason' => 'none: no case yet']]);
});

it('lists a command without a cases file as uncovered and does not fail', function (): void {
    $selected = proofSelect(['swamp/workflows/workflow-firewall-direct.yaml']);

    expect($selected['uncovered'])->toBe([['path' => 'swamp/workflows/workflow-firewall-direct.yaml', 'reason' => 'no cases file for firewall:remove']])
        ->and($selected['files'])->toHaveCount(2)->and($selected['nothing'])->toBeFalse();
});

it('keeps an uncovered path next to the selected cases', function (): void {
    $selected = proofSelect(['swamp/proof/firewall/x.cases.yaml', 'apps/gateway/app/Foo.php']);

    expect($selected['files'])->toHaveCount(2)
        ->and($selected['uncovered'])->toContain(['path' => 'apps/gateway/app/Foo.php', 'reason' => 'none: no case yet']);
});

it('refuses a path that no line matches and names it', function (): void {
    $refusal = proofRefusal(fn () => proofSelect(['docs/a.md', 'unknown/new.file']));

    expect($refusal->errorCode)->toBe('proof.path_unmapped')->and($refusal->details['path'])->toBe('unknown/new.file')
        ->and($refusal->getMessage())->toContain('unknown/new.file');
});

it('selects the cases file of each command for a shared path', function (): void {
    $selected = proofSelect(['swamp/workflows/workflow-shared-x.yaml']);

    expect($selected['files'])->toBe(['swamp/proof/firewall/allow.cases.yaml', 'swamp/proof/node/role-add.cases.yaml'])
        ->and($selected['cases'])->toBe([['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml'], ['command' => 'node:role:add', 'file' => 'swamp/proof/node/role-add.cases.yaml']]);
});

it('turns further colons of the command name into dashes', function (): void {
    expect(proofSelect(['swamp/workflows/workflow-shared-x.yaml'])['files'])->toContain('swamp/proof/node/role-add.cases.yaml');
});

it('selects every case for a wildcard path', function (): void {
    $selected = proofSelect(['bin/proof-fleet']);

    expect($selected['files'])->toBe(PROOF_CASES_FILES)->and($selected['because'])->toBe('wildcard')
        ->and(array_column($selected['cases'], 'command'))->toBe(['firewall:allow', 'firewall:deny', 'node:role-add']);
});

it('lets the last matching line win', function (): void {
    // apps/web/** is a later skip than apps/** (none), and swamp/proof/reset/** (*) comes after docs/** (skip).
    expect(proofSelect(['apps/web/x.ts'])['nothing'])->toBeTrue()
        ->and(proofSelect(['apps/web/x.ts'])['uncovered'])->toBe([])
        ->and(proofSelect(['swamp/proof/reset/README.md'])['because'])->toBe('wildcard');
});

it('selects every case when the selection list changed, and the change cannot narrow it', function (): void {
    $selected = proofSelect(['swamp/proof/selection.txt', 'docs/a.md'], list: PROOF_LIST."\nswamp/proof/selection.txt   skip\n");

    expect($selected['because'])->toBe('selection_list_changed')->and($selected['files'])->toBe(PROOF_CASES_FILES);
});

it('selects every case when GitHub lists an incomplete file list', function (): void {
    $selected = proofSelect(['docs/a.md'], complete: false);

    expect($selected['because'])->toBe('file_list_incomplete')->and($selected['files'])->toBe(PROOF_CASES_FILES);
});

it('selects every case for --all, also when only documentation changed', function (): void {
    $selected = proofSelect(['docs/a.md'], all: true);

    expect($selected['because'])->toBe('all_requested')->and($selected['files'])->toBe(PROOF_CASES_FILES)->and($selected['nothing'])->toBeFalse();
    expect(proofSelect(['docs/a.md'])['nothing'])->toBeTrue();
});

it('selects every case when the commit has no selection list', function (): void {
    $selected = proofSelect(['unknown/new.file'], list: null);

    expect($selected['because'])->toBe('selection_list_missing')->and($selected['files'])->toBe(PROOF_CASES_FILES);
});

it('counts the old path of a rename, and a delete, as changed', function (): void {
    $renamed = proofSelect([['docs/moved.md', 'renamed', 'swamp/workflows/workflow-firewall-direct.yaml']]);
    $deleted = proofSelect([['swamp/workflows/workflow-firewall-direct.yaml', 'removed']]);

    expect($renamed['files'])->toHaveCount(2)->and($deleted['files'])->toHaveCount(2);
});

it('refuses an unmapped new path of a rename out of a mapped path', function (): void {
    $refusal = proofRefusal(fn () => proofSelect([['unknown/moved.file', 'renamed', 'swamp/workflows/workflow-firewall-direct.yaml']]));

    expect($refusal->details['path'])->toBe('unknown/moved.file');
});

it('matches globs: ** crosses directories, * stays in one name, ** and a slash match no directory', function (string $glob, string $path, bool $matches): void {
    $list = ProofSelectionList::parse($glob.' skip');

    expect($list->match($path) !== null)->toBe($matches);
})->with([
    ['docs/**', 'docs/a/b/c.md', true],
    ['docs/**', 'docs', false],
    ['**/*.md', 'README.md', true],
    ['**/*.md', 'a/b/README.md', true],
    ['**/*.md', 'a/b/README.txt', false],
    ['*', 'README.md', true],
    ['*', 'a/README.md', false],
    ['a/*/c', 'a/b/c', true],
    ['a/*/c', 'a/b/b/c', false],
    ['a/?.sh', 'a/x.sh', true],
    ['a/?.sh', 'a/xy.sh', false],
    ['bin/proof-fleet', 'bin/proof-fleet', true],
    ['bin/proof-fleet', 'bin/proof-fleet2', false],
]);

it('refuses a malformed selection list', function (string $text): void {
    expect(proofRefusal(fn () => ProofSelectionList::parse($text))->errorCode)->toBe('proof.selection_invalid');
})->with([
    'a glob without a value' => ['docs/**'],
    'none without a reason' => ['docs/** none:'],
    'a value that is not a command' => ['docs/** firewall'],
    'an upper case command' => ['docs/** Firewall:Allow'],
    'a glob with a space-free brace' => ['do{cs,x}/** skip'],
]);

it('finds only cases files of the form swamp/proof/<family>/<command>.cases.yaml', function (): void {
    expect(ProofSelection::casesFiles([
        'swamp/proof/reset/README.md',
        'swamp/proof/firewall/deny.cases.yaml',
        'swamp/proof/firewall/allow.cases.yaml',
        'swamp/proof/firewall/allow.md',
        'swamp/proof/firewall/check.py',
        'swamp/proof/firewall/cases/old.cases.yaml',
        'swamp/proof/firewall.allow.json',
    ]))->toBe(['swamp/proof/firewall/allow.cases.yaml', 'swamp/proof/firewall/deny.cases.yaml']);
});

it('classifies every tracked path of the repository with the checked-in selection list', function (): void {
    $root = dirname(base_path(), 2);
    if (! is_file($root.'/swamp/proof/selection.txt') || ! file_exists($root.'/.git')) {
        $this->markTestSkipped('Needs the repository checkout.');
    }
    $list = ProofSelectionList::parse((string) file_get_contents($root.'/swamp/proof/selection.txt'));
    $tracked = array_filter(explode("\n", (string) shell_exec('git -C '.escapeshellarg($root).' ls-files')));

    expect($tracked)->not->toBeEmpty();
    $unmapped = array_values(array_filter($tracked, static fn (string $path): bool => $list->match($path) === null));
    expect($unmapped)->toBe([]);
});
