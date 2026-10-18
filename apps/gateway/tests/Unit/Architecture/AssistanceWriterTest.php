<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/** @return list<int> */
function assistance_write_lines(string $source): array
{
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new ParentConnectingVisitor);
    $nodes = $traverser->traverse($nodes);
    $finder = new NodeFinder;
    $fields = ['assistance_requested', 'assistance_kind', 'assistance_question', 'assistance_reason'];
    $scopeOf = static function (Node $node): ?Node {
        while (($node = $node->getAttribute('parent')) instanceof Node) {
            if ($node instanceof FunctionLike) {
                return $node;
            }
        }

        return null;
    };
    $containsField = static fn (Node $node): bool => $finder->findFirst([$node], static fn (Node $item): bool => ($item instanceof ArrayItem && $item->key instanceof String_ && in_array($item->key->value, $fields, true))
        || ($item instanceof ArrayDimFetch && $item->dim instanceof String_ && in_array($item->dim->value, $fields, true))) !== null;
    /** @var list<MethodCall|StaticCall> $writes */
    $writes = $finder->find($nodes, static fn (Node $node): bool => ($node instanceof MethodCall || $node instanceof StaticCall)
        && $node->name instanceof Identifier
        && in_array(strtolower($node->name->name), ['update', 'insert', 'insertorignore', 'upsert', 'updateorinsert', 'updateorcreate', 'firstorcreate'], true));
    $lines = [];
    foreach ($writes as $write) {
        $scope = $scopeOf($write);
        $assignments = array_filter($finder->findInstanceOf($scope ?? $nodes, Assign::class), static fn (Assign $assignment): bool => $scopeOf($assignment) === $scope);
        $tainted = [];
        $usesTainted = static fn (Node $node): bool => $finder->findFirst([$node], static fn (Node $item): bool => $item instanceof Variable && is_string($item->name) && isset($tainted[$item->name])) !== null;
        do {
            $before = $tainted;
            foreach ($assignments as $assignment) {
                $target = $assignment->var instanceof ArrayDimFetch ? $assignment->var->var : $assignment->var;
                if ($target instanceof Variable && is_string($target->name)
                    && ($containsField($assignment) || $usesTainted($assignment->expr))) {
                    $tainted[$target->name] = true;
                }
            }
            // Arrow functions capture by value, so each propagation step uses the newly found variables.
            $usesTainted = static fn (Node $node): bool => $finder->findFirst([$node], static fn (Node $item): bool => $item instanceof Variable && is_string($item->name) && isset($tainted[$item->name])) !== null;
        } while ($before !== $tainted);
        foreach ($write->getArgs() as $arg) {
            if ($containsField($arg) || $usesTainted($arg)) {
                $lines[] = $write->getStartLine();
            }
        }
    }

    return array_values(array_unique($lines));
}

it('keeps query-builder assistance writes inside TaskAssistance', function (): void {
    $violations = [];
    $appPath = dirname(__DIR__, 3).'/app';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || $file->getPathname() === $appPath.'/Domain/Tasks/TaskAssistance.php') {
            continue;
        }
        foreach (assistance_write_lines((string) file_get_contents($file->getPathname())) as $line) {
            $violations[] = $file->getPathname().':'.$line;
        }
    }

    expect($violations)->toBe([]);
});

it('detects model, relation and raw table assistance write bypasses', function (string $write): void {
    expect(assistance_write_lines('<?php '.$write))->not->toBe([]);
})->with([
    'model builder' => ["Task::query()->update(['assistance_requested' => true]);"],
    'relation builder' => ["\$group->tasks()->update(['assistance_question' => 'Which mirror?']);"],
    'variable payload' => ["\$values = ['assistance_requested' => true]; DB::table('tasks')->update(\$values);"],
    'aliased payload' => ["\$values = ['assistance_requested' => true]; \$payload = [...\$values]; DB::table('tasks')->update(\$payload);"],
    'array assignment' => ["\$values['assistance_reason'] = 'Blocked'; Task::query()->update(\$values);"],
    'raw table' => ["DB::table('tasks')->where('id', 1)->update(['assistance_kind' => 'direction']);"],
]);
