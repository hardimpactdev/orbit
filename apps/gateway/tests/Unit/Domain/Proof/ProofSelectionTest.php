<?php

declare(strict_types=1);

use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofRuleKind;
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
swamp/proof/runner/**                         *
tools/swamp-proof-guest/**                    *
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
    $selected = proofSelect(['tools/swamp-proof-guest/terminal.py']);

    expect($selected['files'])->toBe(PROOF_CASES_FILES)->and($selected['because'])->toBe('wildcard')
        ->and(array_column($selected['cases'], 'command'))->toBe(['firewall:allow', 'firewall:deny', 'node:role-add']);
});

it('lets the last matching line win', function (): void {
    // apps/web/** is a later skip than apps/** (none), and swamp/proof/runner/** (*) comes after docs/** (skip).
    expect(proofSelect(['apps/web/x.ts'])['nothing'])->toBeTrue()
        ->and(proofSelect(['apps/web/x.ts'])['uncovered'])->toBe([])
        ->and(proofSelect(['swamp/proof/runner/runner.py'])['because'])->toBe('wildcard');
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
    ['bin/tool', 'bin/tool', true],
    ['bin/tool', 'bin/tool2', false],
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
        'swamp/proof/runner/README.md',
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

it('carries the merge base of the commit into the selection, for the proof of a new case', function (): void {
    $byCommand = ProofSelection::select(PROOF_LIST, proofChanges(['swamp/workflows/workflow-firewall-direct.yaml']), false, PROOF_CASES_FILES);
    $everything = ProofSelection::select(PROOF_LIST, proofChanges(['docs/a.md']), true, PROOF_CASES_FILES);

    expect($byCommand->mergeBase)->toBe(str_repeat('a', 40))->and($everything->mergeBase)->toBe(str_repeat('a', 40));
});

const FIREWALL_COMMANDS = ['firewall:allow', 'firewall:deny', 'firewall:list', 'firewall:remove'];

/** The family directories whose every file implements the firewall commands; the router LAN ingress files are the exception. */
const FIREWALL_DIRECTORIES = [
    'apps/gateway/app/Actions/Firewall/',
    'apps/gateway/app/Data/Firewall/',
    'apps/gateway/app/Domain/Firewall/',
    'apps/gateway/app/Http/Requests/Firewall/',
    'apps/gateway/app/Infrastructure/Firewall/',
    'apps/cli/app/Commands/Firewall/',
    'packages/php-sdk/src/Requests/Firewall/',
    'packages/php-sdk/src/Responses/Firewall/',
];

function firewallSelectionList(): ?ProofSelectionList
{
    $root = dirname(base_path(), 2);

    return is_file($root.'/swamp/proof/selection.txt') && file_exists($root.'/.git')
        ? ProofSelectionList::parse((string) file_get_contents($root.'/swamp/proof/selection.txt')) : null;
}

it('maps the code of the firewall commands to them in the checked-in selection list', function (string $path): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');

    expect($list->match($path)?->commands)->toBe(FIREWALL_COMMANDS);
})->with([
    'the store action' => ['apps/gateway/app/Actions/Firewall/StoreFirewallRuleAction.php'],
    'the data' => ['apps/gateway/app/Data/Firewall/FirewallRuleData.php'],
    'the route file' => ['apps/gateway/routes/api/firewall.php'],
    'the controller' => ['apps/gateway/app/Http/Controllers/Api/FirewallRulesController.php'],
    'the managed rule catalog' => ['apps/gateway/app/Infrastructure/Firewall/NodeFirewallRuleCatalog.php'],
    'the rule migration' => ['apps/gateway/database/migrations/2027_01_09_120022_identify_firewall_rules_by_tuple.php'],
    'the allow CLI command' => ['apps/cli/app/Commands/Firewall/AllowFirewallRuleCommand.php'],
    'the CLI vocabulary' => ['apps/cli/app/Support/CommandVocabulary/firewall.php'],
    'the SDK request' => ['packages/php-sdk/src/Requests/Firewall/StoreFirewallRuleRequest.php'],
]);

it('selects role addition for its implementation in the checked-in list', function (string $path): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');

    expect($list->match($path)?->commands)->toBe(['node:role:add']);
})->with([
    'apps/cli/app/Commands/Nodes/AddNodeRoleCommand.php',
    'apps/gateway/app/Actions/Nodes/AdmitNodeRoleOperationAction.php',
    'apps/gateway/app/Http/Controllers/Api/NodeRolesController.php',
    'apps/gateway/app/Http/Requests/Nodes/AddNodeRoleRequest.php',
    'apps/gateway/app/Domain/Nodes/NativeNodeRoleAddInput.php',
    'packages/php-sdk/src/Requests/Nodes/AddNodeRoleRequest.php',
    'swamp/workflows/workflow-operation-node-role-add.yaml',
    'swamp/ansible/operation-node-role-add.yml',
    'swamp/proof/node/role-add.cases.yaml',
]);

it('selects every case for shared proof entry and human failure rendering', function (string $path): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');

    expect($list->match($path)?->kind)->toBe(ProofRuleKind::Everything);
})->with([
    'apps/cli/app/Commands/Proof/ProofCommand.php',
    'apps/gateway/app/Domain/Proof/ProofVerifyIntent.php',
    'apps/gateway/app/Http/Requests/Proof/StartProofVerifyRequest.php',
    'packages/php-sdk/src/Requests/Proof/VerifyProofRequest.php',
    'apps/cli/app/Support/GatewayFailureRenderer.php',
]);

it('keeps the router LAN ingress code out of the firewall commands', function (string $path): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');

    expect($list->match($path)?->kind)->toBe(ProofRuleKind::Skip);
})->with([
    'the domain policy' => ['apps/gateway/app/Domain/Firewall/RouterLanIngressPolicy.php'],
    'the native reconciler' => ['apps/gateway/app/Infrastructure/Firewall/NativeRouterLanIngressReconciler.php'],
    'the service provider, which binds only the router LAN classes' => ['apps/gateway/app/Providers/Domains/FirewallServiceProvider.php'],
]);

it('selects the firewall commands for every tracked file of the firewall directories', function (): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');
    $root = dirname(base_path(), 2);
    $tracked = array_filter(explode("\n", (string) shell_exec('git -C '.escapeshellarg($root).' ls-files')));
    $files = array_values(array_filter($tracked, static fn (string $path): bool => ! str_contains($path, 'RouterLanIngress')
        && array_filter(FIREWALL_DIRECTORIES, static fn (string $directory): bool => str_starts_with($path, $directory)) !== []));

    expect($files)->not->toBeEmpty();
    $unselected = array_values(array_filter($files, static fn (string $path): bool => $list->match($path)?->commands !== FIREWALL_COMMANDS));
    expect($unselected)->toBe([]);
});

it('runs every case for the code that every command runs through', function (string $path): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');

    expect($list->match($path)?->kind)->toBe(ProofRuleKind::Everything);
})->with([
    'the start action' => ['apps/gateway/app/Actions/Operations/StartOperationAction.php'],
    'the operation store' => ['apps/gateway/app/Domain/Operations/OperationStore.php'],
    'the operation request' => ['apps/gateway/app/Http/Requests/Operations/StartsOperation.php'],
    'the operations controller' => ['apps/gateway/app/Http/Controllers/Api/OperationsController.php'],
    'the operation model' => ['apps/gateway/app/Models/Operation.php'],
    'the Swamp run starter' => ['apps/gateway/app/Infrastructure/Swamp/StartSwampRun.php'],
    'a request middleware' => ['apps/gateway/app/Http/Middleware/HandleOperationIdempotency.php'],
    'the proof runner' => ['swamp/proof/runner/runner.py'],
    'the CLI base command' => ['apps/cli/app/Commands/GatewayCommand.php'],
    'the CLI operation follower' => ['apps/cli/app/Commands/Concerns/FollowsOperations.php'],
    'the CLI connector factory' => ['apps/cli/app/Services/GatewayConnectorFactory.php'],
    'the CLI failure renderer' => ['apps/cli/app/Support/GatewayFailureRenderer.php'],
    'the SDK connector' => ['packages/php-sdk/src/GatewayConnector.php'],
    'the SDK operation response' => ['packages/php-sdk/src/Responses/Operations/OperationResponse.php'],
]);

it('keeps documentation and the unit tests of the proof tooling out of the shared code', function (string $path): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');

    expect($list->match($path)?->kind)->toBe(ProofRuleKind::Skip);
})->with([
    'a README in a shared directory' => ['apps/cli/app/Support/Console/README.md'],
    'a proof runner test' => ['swamp/proof/runner/tests/test_runner.py'],
    'a firewall extension test' => ['swamp/extensions/behaviors/operations/firewall/firewall_test.ts'],
]);

it('runs every case when a shared file changes', function (): void {
    firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');
    $selected = proofSelect(['apps/gateway/app/Domain/Operations/OperationStore.php'], list: (string) file_get_contents(dirname(base_path(), 2).'/swamp/proof/selection.txt'));

    expect($selected['because'])->not->toBeNull()->and($selected['files'])->toBe(PROOF_CASES_FILES);
});

it('names only tracked files in the lines that select every case', function (): void {
    firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');
    $root = dirname(base_path(), 2);
    $tracked = array_flip(array_filter(explode("\n", (string) shell_exec('git -C '.escapeshellarg($root).' ls-files'))));
    preg_match_all('~^(\S+)\s+\*$~m', (string) file_get_contents($root.'/swamp/proof/selection.txt'), $lines);
    $named = array_values(array_filter($lines[1], static fn (string $glob): bool => ! str_contains($glob, '*') && ! str_contains($glob, '?')));

    expect($named)->not->toBeEmpty();
    $missing = array_values(array_filter($named, static fn (string $path): bool => ! isset($tracked[$path])));
    expect($missing)->toBe([]);
});

it('selects every case for every tracked file of the shared directories', function (): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');
    $root = dirname(base_path(), 2);
    $tracked = array_filter(explode("\n", (string) shell_exec('git -C '.escapeshellarg($root).' ls-files')));
    $directories = [
        'apps/gateway/app/Actions/Operations/', 'apps/gateway/app/Data/Operations/', 'apps/gateway/app/Domain/Operations/', 'apps/gateway/app/Http/Requests/Operations/',
        'apps/cli/app/Commands/Operations/', 'apps/cli/app/Console/', 'apps/cli/app/Exceptions/', 'apps/cli/app/Repositories/', 'apps/cli/app/Services/Profile/',
        'apps/cli/app/Support/Console/', 'apps/cli/app/Support/Operations/', 'packages/php-sdk/src/Support/', 'packages/php-sdk/src/Requests/Operations/',
        'packages/php-sdk/src/Responses/Operations/',
    ];
    $files = array_values(array_filter($tracked, static fn (string $path): bool => ! str_ends_with($path, '.md') && ! in_array(basename($path), ['OperationBudgetTable.php', 'OperationRegistryCheck.php', 'GatewayWorkflowGrantCheck.php'], true)
        && array_filter($directories, static fn (string $directory): bool => str_starts_with($path, $directory)) !== []));

    expect($files)->not->toBeEmpty();
    $unselected = array_values(array_filter($files, static fn (string $path): bool => $list->match($path)?->kind !== ProofRuleKind::Everything));
    expect($unselected)->toBe([]);
});

/** The two file sets that started a proof of every case, with the helper of OperationRunBudgets (`longestFixedSeconds`) at the place where it lives now, in OperationBudgetTable. */
dataset('swamp unit changes that touch no command', [
    '#3080, the serve run cap in the unit' => [[
        'apps/gateway/app/Domain/Nodes/SwampRoleUnits.php',
        'apps/gateway/app/Domain/Operations/OperationBudgetTable.php',
        'apps/gateway/tests/Unit/Domain/Nodes/SwampRoleUnitsTest.php',
        'docs/decisions/0243-serve-swamp-through-a-swamp-role.md',
        'docs/swamp/porting.md',
    ]],
    '74304ed, the cap follows the fixed budgets only' => [[
        'apps/gateway/app/Domain/Operations/OperationBudgetTable.php',
        'docs/decisions/0243-serve-swamp-through-a-swamp-role.md',
    ]],
    'a Swamp manifest and config' => [['swamp/manifest.yaml', '.swamp.yaml', 'swamp/config/gateway-workflow-grants.json']],
]);

it('selects no case for a change to a Swamp unit or a budget that touches no command', function (array $files): void {
    firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');
    $selected = proofSelect($files, list: (string) file_get_contents(dirname(base_path(), 2).'/swamp/proof/selection.txt'));

    expect($selected['because'])->toBeNull()->and($selected['cases'])->toBe([])->and($selected['nothing'])->toBeTrue();
})->with('swamp unit changes that touch no command');

it('selects only the firewall cases when a budget changes with the code of a firewall command, never every case', function (): void {
    firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');
    $selected = proofSelect(['apps/gateway/app/Domain/Operations/OperationBudgetTable.php', 'apps/gateway/app/Actions/Firewall/StoreFirewallRuleAction.php'],
        list: (string) file_get_contents(dirname(base_path(), 2).'/swamp/proof/selection.txt'));

    expect($selected['because'])->toBeNull()->and(array_column($selected['cases'], 'command'))->toBe(['firewall:allow', 'firewall:deny']);
});

it('keeps the budget table out of the shared code but not the rules that read it', function (string $path, ProofRuleKind $kind): void {
    $list = firewallSelectionList() ?? $this->markTestSkipped('Needs the repository checkout.');

    expect($list->match($path)?->kind)->toBe($kind);
})->with([
    'the budget table' => ['apps/gateway/app/Domain/Operations/OperationBudgetTable.php', ProofRuleKind::None],
    'the budget rules' => ['apps/gateway/app/Domain/Operations/OperationRunBudgets.php', ProofRuleKind::Everything],
    'the kinds' => ['apps/gateway/app/Domain/Operations/OperationKinds.php', ProofRuleKind::Everything],
]);
