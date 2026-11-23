<?php

declare(strict_types=1);

use App\Documentation\BlockedPhrases;
use App\Documentation\DocumentationRepository;
use App\Librarian\Rules\DocumentationProseStyleRule;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-prose-style-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($this->root.'/decisions', 0777, true);
    $files->makeDirectory($this->root.'/generated', 0777, true);
    $files->makeDirectory($this->root.'/reference', 0777, true);
    $this->rule = new DocumentationProseStyleRule(
        new DocumentationRepository($this->root, $this->root.'/generated/context.json', []),
        new BlockedPhrases(['due to the fact that', 'utilize', 'functions as', 'seamless']),
        ['a closer look', 'why it matters'],
    );
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

it('accepts direct, plain prose and specific headings', function (): void {
    file_put_contents(
        $this->root.'/reference/apps.md',
        "# Apps\n\n## How Orbit deploys a release\n\nOrbit uses the Git commit you choose.\n",
    );

    expect($this->rule->check())->toBe([]);
});

it('rejects filler phrases and vague headings outside decisions and generated files', function (): void {
    file_put_contents(
        $this->root.'/reference/apps.md',
        "# Apps\n\n## A closer look\n\nYou can utilize this flag due to the fact that it gives seamless deploys.\n\n## Why it matters\n\nThe Gateway functions as the control plane.\n",
    );
    file_put_contents($this->root.'/decisions/0009-old.md', "# ADR 0009: Old\n\n## Why it matters\n\nThis functions as context.\n");
    file_put_contents($this->root.'/generated/index.md', "# Index\n\nutilize\n");

    $findings = $this->rule->check();

    expect(array_map(
        static fn (Finding $finding): string => "{$finding->path}:{$finding->line} {$finding->message}",
        $findings,
    ))
        ->toBe([
            'docs/reference/apps.md:5 Blocked phrase `due to the fact that`. Use plain, direct language instead.',
            'docs/reference/apps.md:5 Blocked phrase `utilize`. Use plain, direct language instead.',
            'docs/reference/apps.md:5 Blocked phrase `seamless`. Use plain, direct language instead.',
            'docs/reference/apps.md:9 Blocked phrase `functions as`. Use plain, direct language instead.',
            'docs/reference/apps.md:3 Vague heading `A closer look`. Replace it with a specific description of the section.',
            'docs/reference/apps.md:7 Vague heading `Why it matters`. Replace it with a specific description of the section.',
        ])
        ->and($findings[0]->severity)
        ->toBe(FindingSeverity::Error)
        ->and($findings[0]->rule)
        ->toBe('orbit.docs_prose_style');
});

it('rejects em dashes in prose but allows them in inline code and table cells', function (): void {
    file_put_contents($this->root.'/reference/apps.md', <<<'MARKDOWN'
        # Apps

        Orbit manages your machines—called Nodes—over WireGuard.

        A missing value shows `—`.

        | Field | Default |
        | --- | --- |
        | `label` | — |
        MARKDOWN);

    $findings = $this->rule->check();

    expect(array_map(
        static fn (Finding $finding): string => "{$finding->path}:{$finding->line} {$finding->message}",
        $findings,
    ))->toBe([
        'docs/reference/apps.md:3 Avoid em dashes in prose. Use commas, parentheses, or separate sentences instead.',
    ]);
});

it('ignores inline code and fenced code blocks', function (): void {
    file_put_contents($this->root.'/reference/apps.md', <<<'MARKDOWN'
        # Apps

        Pass `--seamless` to test `utilize`.

        ```markdown
        ## A closer look

        This serves as an example.
        ```

        Orbit uses plain defaults.
        MARKDOWN);

    expect($this->rule->check())->toBe([]);
});
