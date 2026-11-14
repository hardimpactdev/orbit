<?php

declare(strict_types=1);

use App\Documentation\DocsImpact;

/** The publish copy of the waiver line rule in TypeScript, read from its source as the literal after `const WaiverLine =`. */
function publish_waiver_pattern(): string
{
    $source = (string) file_get_contents(__DIR__.'/../../../../swamp/extensions/behaviors/operations/tasks/publish.ts');
    expect(preg_match('#^const WaiverLine = /(.+)/;$#m', $source, $match))->toBe(1);

    return '/'.$match[1].'/';
}

it('holds the publish copy of the waiver line rule equal to its owner on the same lines', function (string $line, bool $waiver): void {
    expect(preg_match(DocsImpact::WAIVER_LINE, $line))->toBe($waiver ? 1 : 0)
        ->and(preg_match(publish_waiver_pattern(), $line))->toBe($waiver ? 1 : 0);
})->with([
    'a page and a reason' => ['Docs-unaffected: docs/reference/tasks.md: the change matches the documented behaviour.', true],
    'an mdx page' => ['Docs-unaffected: docs/cli/task/show.mdx:  generated.', true],
    'no reason' => ['Docs-unaffected: docs/reference/tasks.md:', false],
    'a path outside docs' => ['Docs-unaffected: /etc/passwd: no.', false],
    'a page that is no Markdown' => ['Docs-unaffected: docs/flows-data.js: built.', false],
    'a wildcard' => ['Docs-unaffected: docs/*.md: all.', false],
    'another prefix' => ['docs-unaffected: docs/reference/tasks.md: lower case.', false],
    'a space in the path' => ['Docs-unaffected: docs/my page.md: spaced.', false],
]);
