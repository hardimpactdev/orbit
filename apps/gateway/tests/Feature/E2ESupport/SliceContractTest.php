<?php

declare(strict_types=1);

describe('slice contract', function (): void {
    it('exposes the approved states and public function signatures', function (): void {
        require_once repo_path('bin/orbit-loop-contract.php');

        expect(ORBIT_LOOP_SLICE_STATES)
            ->toBe(['pending', 'ready', 'building', 'complete', 'blocked']);

        $slices = new ReflectionFunction('orbitLoopSlices');
        $frame = new ReflectionFunction('orbitLoopSliceFrameProblems');
        $finalization = new ReflectionFunction('orbitLoopSliceFinalizationProblems');

        expect($slices->getNumberOfParameters())
            ->toBe(1)
            ->and($slices->getParameters()[0]->getName())
            ->toBe('markdown')
            ->and($slices->getParameters()[0]->getType()?->getName())
            ->toBe('string')
            ->and($slices->getReturnType()?->getName())
            ->toBe('array')
            ->and($frame->getNumberOfParameters())
            ->toBe(2)
            ->and($frame->getParameters()[0]->getName())
            ->toBe('markdown')
            ->and($frame->getParameters()[1]->getName())
            ->toBe('orbitDir')
            ->and($frame->getParameters()[0]->getType()?->getName())
            ->toBe('string')
            ->and($frame->getParameters()[1]->getType()?->getName())
            ->toBe('string')
            ->and($frame->getReturnType()?->getName())
            ->toBe('array')
            ->and($finalization->getNumberOfParameters())
            ->toBe(3)
            ->and(array_map(
                static fn (ReflectionParameter $parameter): string => $parameter->getName(),
                $finalization->getParameters(),
            ))
            ->toBe(['markdown', 'worktree', 'featureTip'])
            ->and(array_map(static fn (ReflectionParameter $parameter): ?string => $parameter
                ->getType()
                ?->getName(), $finalization->getParameters()))
            ->toBe(['string', 'string', 'string'])
            ->and($finalization->getReturnType()?->getName())
            ->toBe('array');
    });

    it('parses exact numbered rows into the public list shape', function (): void {
        require_once repo_path('bin/orbit-loop-contract.php');

        $loop = slice_contract_table([
            ['.orbit/slices/01-one.md', 'ready',   'none'],
            ['.orbit/slices/02-two.md', 'blocked', 'none'],
        ]);

        expect(orbitLoopSlices($loop))->toBe([
            ['path' => '.orbit/slices/01-one.md', 'state' => 'ready', 'checkpoint' => 'none'],
            ['path' => '.orbit/slices/02-two.md', 'state' => 'blocked', 'checkpoint' => 'none'],
        ]);
    });

    it('rejects malformed Slices tables deterministically', function (string $table, string $message): void {
        require_once repo_path('bin/orbit-loop-contract.php');

        expect(fn (): array => orbitLoopSlices($table))
            ->toThrow(RuntimeException::class, $message);
    })->with([
        'missing columns' => [
            "## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-one.md` | ready |\n",
            'Slices table row must have exactly 3 columns',
        ],
        'extra columns' => [
            "## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\n| `.orbit/slices/01-one.md` | ready | none | x |\n",
            'Slices table row must have exactly 3 columns',
        ],
        'wrong header' => [
            str_replace('| Slice | State | Checkpoint |', '| Path | State | Checkpoint |', slice_contract_table([[
                '.orbit/slices/01-one.md',
                'ready',
                'none',
            ]])),
            'Slices table must have exact headers',
        ],
        'lowercase section heading' => [
            str_replace('## Slices', '## slices', slice_contract_table([['.orbit/slices/01-one.md', 'ready', 'none']])),
            'Slices section heading must be exactly',
        ],
        'duplicate section heading' => [
            slice_contract_table([['.orbit/slices/01-one.md', 'ready', 'none']])."\n## Slices\n",
            'duplicate Slices section',
        ],
        'wrong separator' => [
            str_replace('| --- | --- | --- |', '| --- | --- |', slice_contract_table([[
                '.orbit/slices/01-one.md',
                'ready',
                'none',
            ]])),
            'Slices table must have exact separators',
        ],
        'malformed data prose' => [
            "## Slices\n\n| Slice | State | Checkpoint |\n| --- | --- | --- |\nprose row\n",
            'duplicate slice path',
        ],
        'unsafe path' => [
            slice_contract_table([['.orbit/slices/one.md', 'ready', 'none']]),
            'unsafe slice packet path',
        ],
        'duplicate row' => [
            slice_contract_table([
                ['.orbit/slices/01-one.md', 'ready',   'none'],
                ['.orbit/slices/01-one.md', 'blocked', 'none'],
            ]),
            'duplicate slice path',
        ],
        'unknown state' => [
            slice_contract_table([['.orbit/slices/01-one.md', 'waiting', 'none']]),
            'invalid slice state',
        ],
        'invalid checkpoint' => [
            slice_contract_table([['.orbit/slices/01-one.md', 'ready', str_repeat('a', 39)]]),
            'invalid slice checkpoint',
        ],
    ]);

    it('does not couple pure row parsing to checkpoint state consistency', function (): void {
        require_once repo_path('bin/orbit-loop-contract.php');

        expect(orbitLoopSlices(slice_contract_table([
            ['.orbit/slices/01-one.md', 'complete', 'none'],
            ['.orbit/slices/02-two.md', 'pending',  str_repeat('a', 40)],
        ])))
            ->toHaveCount(2);
    });

    it('rejects malformed numbered packet content deterministically', function (string $packet, string $message): void {
        require_once repo_path('bin/orbit-loop-contract.php');
        $fixture = slice_contract_fixture('malformed-packet');
        file_put_contents($fixture.'/slices/01-one.md', $packet);

        try {
            expect(implode("\n", orbitLoopSliceFrameProblems(
                slice_contract_table([['.orbit/slices/01-one.md', 'ready', 'none']]),
                $fixture,
            )))
                ->toContain($message);
        } finally {
            slice_contract_cleanup($fixture);
        }
    })->with(function (): array {
        $valid = slice_contract_packet('01-one', 'none');

        return [
            'missing H1' => [
                str_replace("# Orbit Feature Slice\n", '', $valid),
                'requires exactly one # Orbit Feature Slice',
            ],
            'wrong H1' => [
                str_replace('# Orbit Feature Slice', '# Wrong', $valid),
                'requires exactly one # Orbit Feature Slice',
            ],
            'duplicate H1' => [$valid."\n# Orbit Feature Slice\n", 'requires exactly one # Orbit Feature Slice'],
            'additional H1' => [$valid."\n# Additional heading\n", 'requires exactly one # Orbit Feature Slice'],
            'reordered metadata' => [
                str_replace(
                    "- Slice: 01-one\n- Depends on: none",
                    "- Depends on: none\n- Slice: 01-one",
                    $valid,
                ),
                'exact metadata preamble',
            ],
            'preamble prose' => [
                str_replace("# Orbit Feature Slice\n\n", "# Orbit Feature Slice\n\nPreamble prose\n", $valid),
                'exact metadata preamble',
            ],
            'missing Slice label' => [str_replace("- Slice: 01-one\n", '', $valid), 'requires exactly one Slice label'],
            'duplicate Slice label' => [$valid."- Slice: 01-one\n", 'requires exactly one Slice label'],
            'missing Depends on label' => [
                str_replace("- Depends on: none\n", '', $valid),
                'requires exactly one Depends on label',
            ],
            'duplicate Depends on label' => [$valid."- Depends on: none\n", 'requires exactly one Depends on label'],
            'unsafe dependency' => [
                str_replace('- Depends on: none', '- Depends on: ../01-one', $valid),
                'unsafe dependency',
            ],
            'unnumbered dependency' => [
                str_replace('- Depends on: none', '- Depends on: one', $valid),
                'unsafe dependency',
            ],
            'wrong-case Slice label' => [
                str_replace('- Slice: 01-one', '- slice: 01-one', $valid),
                'requires exactly one Slice label',
            ],
            'wrong-case Depends label' => [
                str_replace('- Depends on: none', '- depends on: none', $valid),
                'requires exactly one Depends on label',
            ],
            'extra Slice label' => [$valid."- Scope: extra\n", 'unknown packet label'],
            'missing Outcome' => [str_replace("## Outcome\n\n", '', $valid), 'required packet sections'],
            'duplicate Outcome' => [$valid."\n## Outcome\n", 'required packet sections'],
            'extra Notes' => [$valid."\n## Notes\n", 'required packet sections'],
            'reordered sections' => [
                str_replace("## Outcome\n\n## Scope", "## Scope\n\n## Outcome", $valid),
                'required packet sections',
            ],
            'placeholder' => [str_replace('included work', '<pending>', $valid), 'placeholder'],
        ];
    });

    it('requires exact Included, Excluded, Decisions, Product docs, and Focused labels', function (
        string $packet,
        string $message,
    ): void {
        require_once repo_path('bin/orbit-loop-contract.php');
        $fixture = slice_contract_fixture('packet-shape');
        file_put_contents($fixture.'/slices/01-one.md', $packet);

        try {
            expect(implode("\n", orbitLoopSliceFrameProblems(
                slice_contract_table([['.orbit/slices/01-one.md', 'ready', 'none']]),
                $fixture,
            )))
                ->toContain($message);
        } finally {
            slice_contract_cleanup($fixture);
        }
    })->with([
        'missing Included' => [
            str_replace("- Included:\n  - included work\n", '', slice_contract_packet('01-one', 'none')),
            'Included',
        ],
        'missing Excluded' => [
            str_replace("- Excluded:\n  - excluded work\n", '', slice_contract_packet('01-one', 'none')),
            'Excluded',
        ],
        'missing Decisions' => [
            str_replace("- Decisions:\n  - lifecycle contract\n", '', slice_contract_packet('01-one', 'none')),
            'Decisions',
        ],
        'missing Product docs' => [
            str_replace("- Product docs:\n  - feature lifecycle\n", '', slice_contract_packet('01-one', 'none')),
            'Product docs',
        ],
        'missing Focused' => [
            str_replace("- Focused:\n  - parser and graph tests\n", '', slice_contract_packet('01-one', 'none')),
            'Focused',
        ],
    ]);

    it('validates numbered dependencies and deterministic errors', function (array $packets, string $message): void {
        require_once repo_path('bin/orbit-loop-contract.php');
        $fixture = slice_contract_fixture('dependencies');
        $rows = [];

        foreach ($packets as $id => $depends) {
            file_put_contents($fixture.'/slices/'.$id.'.md', slice_contract_packet($id, $depends));
            $rows[] = ['.orbit/slices/'.$id.'.md', 'ready', 'none'];
        }

        try {
            expect(implode("\n", orbitLoopSliceFrameProblems(slice_contract_table($rows), $fixture)))
                ->toContain($message);
        } finally {
            slice_contract_cleanup($fixture);
        }
    })->with([
        'duplicate dependency' => [['01-one' => '02-two,02-two'], 'duplicate dependency'],
        'unknown dependency' => [['01-one' => '09-missing'], 'unknown slice'],
        'self dependency' => [['01-one' => '01-one'], 'cannot depend on itself'],
        'cycle' => [['01-one' => '02-two', '02-two' => '01-one'], 'cyclic slice dependency'],
    ]);

    it('accepts comma-space separated numbered dependencies', function (): void {
        require_once repo_path('bin/orbit-loop-contract.php');
        $fixture = slice_contract_fixture('multi-dependency');
        $rows = [
            ['.orbit/slices/01-one.md',   'ready',   'none'],
            ['.orbit/slices/02-two.md',   'ready',   'none'],
            ['.orbit/slices/03-three.md', 'pending', 'none'],
        ];
        foreach (['01-one' => 'none', '02-two' => 'none', '03-three' => '01-one, 02-two'] as $id => $depends) {
            file_put_contents($fixture.'/slices/'.$id.'.md', slice_contract_packet($id, $depends));
        }

        try {
            expect(orbitLoopSliceFrameProblems(slice_contract_table($rows), $fixture))->toBe([]);
        } finally {
            slice_contract_cleanup($fixture);
        }
    });

    it('rejects unsafe packet files and all unindexed Markdown entries', function (
        callable $prepare,
        string $message,
    ): void {
        require_once repo_path('bin/orbit-loop-contract.php');
        $fixture = slice_contract_fixture('unsafe-packet');
        $prepare($fixture);

        try {
            expect(implode("\n", orbitLoopSliceFrameProblems(
                slice_contract_table([['.orbit/slices/01-one.md', 'ready', 'none']]),
                $fixture,
            )))
                ->toContain($message);
        } finally {
            slice_contract_cleanup($fixture);
        }
    })->with([
        'unindexed' => [
            static function (string $fixture): void {
                file_put_contents($fixture.'/slices/01-one.md', slice_contract_packet('01-one', 'none'));
                file_put_contents($fixture.'/slices/02-two.md', slice_contract_packet('02-two', 'none'));
            },
            'unindexed slice packet',
        ],
        'hidden unindexed' => [
            static function (string $fixture): void {
                file_put_contents($fixture.'/slices/01-one.md', slice_contract_packet('01-one', 'none'));
                file_put_contents($fixture.'/slices/.hidden.md', 'hidden');
            },
            'unindexed slice packet',
        ],
        'non regular leaf' => [
            static function (string $fixture): void {
                mkdir($fixture.'/slices/01-one.md', recursive: true);
            },
            'unsafe slice packet',
        ],
        'missing leaf' => [static function (string $fixture): void {}, 'unsafe slice packet'],
    ]);

    it('rejects symlinked indexed leaves and symlinked packet ancestry', function (): void {
        require_once repo_path('bin/orbit-loop-contract.php');
        $fixture = slice_contract_fixture('symlinks');
        $outside = sys_get_temp_dir().'/orbit-slice-contract-outside-'.bin2hex(random_bytes(4));
        mkdir($outside, recursive: true);
        file_put_contents($outside.'/external.md', slice_contract_packet('01-one', 'none'));
        symlink($outside.'/external.md', $fixture.'/slices/01-one.md');

        try {
            expect(implode("\n", orbitLoopSliceFrameProblems(
                slice_contract_table([['.orbit/slices/01-one.md', 'ready', 'none']]),
                $fixture,
            )))
                ->toContain('unsafe slice packet');
            unlink($fixture.'/slices/01-one.md');
            rmdir($fixture.'/slices');
            symlink($outside, $fixture.'/slices');
            expect(implode("\n", orbitLoopSliceFrameProblems(
                slice_contract_table([['.orbit/slices/01-one.md', 'ready', 'none']]),
                $fixture,
            )))
                ->toContain('unsafe slice packet');
        } finally {
            if (is_link($fixture.'/slices')) {
                unlink($fixture.'/slices');
            } elseif (is_dir($fixture.'/slices')) {
                slice_contract_cleanup($fixture);
            }
            if (is_dir($outside)) {
                unlink($outside.'/external.md');
                rmdir($outside);
            }
            if (is_dir($fixture)) {
                rmdir($fixture);
                rmdir(dirname($fixture));
            }
        }
    });

    it('accepts and rejects phase graphs without ancestry or terminal enforcement', function (
        array $states,
        string $phase,
        array $expected,
    ): void {
        require_once repo_path('bin/orbit-loop-contract.php');
        $fixture = slice_contract_fixture('graph');
        $rows = [];

        foreach ($states as $id => $data) {
            file_put_contents($fixture.'/slices/'.$id.'.md', slice_contract_packet($id, $data[1]));
            $rows[] = ['.orbit/slices/'.$id.'.md', $data[0], $data[2] ?? 'none'];
        }

        try {
            $actual = $phase === 'frame'
                ? orbitLoopSliceFrameProblems(slice_contract_table($rows), $fixture)
                : orbitLoopSliceFinalizationProblems(
                    slice_contract_table($rows),
                    dirname($fixture),
                    str_repeat('e', 40),
                );
            expect($actual)->toBe($expected);
        } finally {
            slice_contract_cleanup($fixture);
        }
    })->with([
        'frame ready work' => [['01-one' => ['ready', 'none'], '02-two' => ['pending', '01-one']], 'frame', []],
        'frame pending dependency-free with ready work' => [
            ['01-one' => ['ready', 'none'], '02-two' => ['pending', 'none']],
            'frame',
            ['pending slice 02-two has complete dependencies'],
        ],
        'frame pending complete dependency with ready work' => [
            [
                '01-one' => ['complete', 'none'],
                '02-two' => ['pending', '01-one'],
                '03-three' => ['ready', 'none'],
            ],
            'frame',
            ['pending slice 02-two has complete dependencies'],
        ],
        'frame ready incomplete dependency' => [
            ['01-one' => ['pending', 'none'], '02-two' => ['ready', '01-one']],
            'frame',
            ['ready slice 02-two has incomplete dependency'],
        ],
        'frame no ready work' => [
            ['01-one' => ['pending', 'none']],
            'frame',
            ['initial graph requires ready dependency-free work'],
        ],
        'frame building forbidden' => [
            ['01-one' => ['building', 'none']],
            'frame',
            ['initial slice graph cannot contain a building slice'],
        ],
        'blocked with ready work' => [['01-one' => ['blocked', 'none'], '02-two' => ['ready', 'none']], 'frame', []],
        'one build complete dependency' => [
            ['01-one' => ['complete', 'none'], '02-two' => ['building', '01-one']],
            'build',
            [],
        ],
        'two builds' => [
            ['01-one' => ['building', 'none'], '02-two' => ['building', 'none']],
            'build',
            ['at most one building slice'],
        ],
        'building incomplete' => [
            ['01-one' => ['ready', 'none'], '02-two' => ['building', '01-one']],
            'build',
            ['building slice 02-two has incomplete dependency'],
        ],
        'pending dependency-free' => [
            ['01-one' => ['complete', 'none'], '02-two' => ['pending', 'none']],
            'build',
            ['pending slice 02-two has complete dependencies'],
        ],
        'pending complete dependency' => [
            ['01-one' => ['complete', 'none'], '02-two' => ['pending', '01-one']],
            'build',
            ['pending slice 02-two has complete dependencies'],
        ],
        'all complete valid' => [['01-one' => ['complete', 'none']], 'build', []],
        'ready remains valid after build' => [['01-one' => ['ready', 'none']], 'build', []],
        'incomplete no work deadlock' => [
            ['01-one' => ['blocked', 'none'], '02-two' => ['pending', '01-one']],
            'build',
            ['incomplete slice graph has no ready or building work'],
        ],
        'deterministic first error' => [
            ['01-one' => ['building', 'none'], '02-two' => ['building', 'none']],
            'build',
            ['at most one building slice'],
        ],
        'complete sha is not ancestry checked' => [
            ['01-one' => ['complete', 'none', str_repeat('a', 40)]],
            'build',
            [],
        ],
    ]);
});

function slice_contract_table(array $rows): string
{
    $lines = ['## Slices', '', '| Slice | State | Checkpoint |', '| --- | --- | --- |'];

    foreach ($rows as $row) {
        $lines[] = '| `'.$row[0].'` | '.$row[1].' | '.$row[2].' |';
    }

    return implode("\n", $lines)."\n";
}

function slice_contract_packet(string $id, string $depends): string
{
    return (
        "# Orbit Feature Slice\n\n"
        ."- Slice: {$id}\n"
        ."- Depends on: {$depends}\n\n"
        ."## Outcome\n\n"
        ."## Scope\n"
        ."- Included:\n"
        ."  - included work\n"
        ."- Excluded:\n"
        ."  - excluded work\n\n"
        ."## Authority\n"
        ."- Decisions:\n"
        ."  - lifecycle contract\n"
        ."- Product docs:\n"
        ."  - feature lifecycle\n\n"
        ."## Proof\n"
        ."- Focused:\n"
        ."  - parser and graph tests\n"
    );
}

function slice_contract_fixture(string $name): string
{
    $root = sys_get_temp_dir().'/orbit-slice-contract-'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($root.'/.orbit/slices', recursive: true);

    return $root.'/.orbit';
}

function slice_contract_cleanup(string $root): void
{
    if (! str_contains($root, '/orbit-slice-contract-')) {
        throw new RuntimeException('refusing to clean an unvalidated slice fixture');
    }

    $orbitDir = $root;
    $worktree = dirname($orbitDir);
    foreach (scandir($orbitDir.'/slices') ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $orbitDir.'/slices/'.$entry;
        if (is_dir($path) && ! is_link($path)) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    rmdir($orbitDir.'/slices');
    rmdir($orbitDir);
    rmdir($worktree);
}
