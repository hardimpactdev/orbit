<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Shared\ResourceOperationException;

/**
 * Chooses the cases of a commit from its changed paths and its selection list
 * ([Choose the cases](/reference/feature-flow#choose-the-cases)). Pure: it reads nothing.
 */
final readonly class ProofSelection
{
    private const string CasesFilePattern = '~\Aswamp/proof/([a-z][a-z0-9_]*)/([a-z][a-z0-9_-]*)\.cases\.yaml\z~D';

    /**
     * @param  list<string>  $treePaths  every path of the commit's tree under `swamp/proof`
     * @return list<string> the cases files, sorted
     */
    public static function casesFiles(array $treePaths): array
    {
        $files = array_values(array_filter($treePaths, static fn (string $path): bool => preg_match(self::CasesFilePattern, $path) === 1));
        sort($files);

        return $files;
    }

    /**
     * @param  string|null  $list  the selection list of the commit, null when the commit has none
     * @param  list<string>  $casesFiles  every cases file of the commit
     *
     * @throws ResourceOperationException proof.selection_invalid, proof.path_unmapped
     */
    public static function select(?string $list, ProofChangedFiles $changes, bool $all, array $casesFiles): ProofSelectionResult
    {
        $paths = $changes->paths();
        $everything = match (true) {
            $all => 'all_requested',
            ! $changes->complete => 'file_list_incomplete',
            $list === null => 'selection_list_missing',
            default => null,
        };
        $commands = [];
        $uncovered = [];
        if ($list !== null) {
            $parsed = ProofSelectionList::parse($list);
            $unmapped = [];
            foreach ($paths as $path) {
                $rule = $parsed->match($path);
                if ($rule === null) {
                    $unmapped[] = $path;

                    continue;
                }
                match ($rule->kind) {
                    ProofRuleKind::Skip => null,
                    ProofRuleKind::None => $uncovered[] = ['path' => $path, 'reason' => 'none: '.$rule->reason],
                    ProofRuleKind::Everything => $everything ??= 'wildcard',
                    ProofRuleKind::Commands => array_map(static function (string $command) use (&$commands, $path): void {
                        $commands[$command] ??= $path;
                    }, $rule->commands),
                };
            }
            if ($unmapped !== []) {
                throw new ResourceOperationException('proof.path_unmapped', 'The path ['.$unmapped[0].'] matches no line of '.ProofSelectionList::Path.'.', 422,
                    details: ['path' => $unmapped[0], 'paths' => array_slice($unmapped, 0, 20)]);
            }
        }

        return $everything !== null
            ? new ProofSelectionResult(array_map(static fn (string $file): array => ['command' => self::labelOf($file), 'file' => $file], $casesFiles), $uncovered, $everything, $changes->mergeBase)
            : self::byCommand($commands, $uncovered, $casesFiles, $changes->mergeBase);
    }

    /** The cases file of `family:a:b` is `swamp/proof/family/a-b.cases.yaml`: further colons of the name become `-`. */
    public static function fileOf(string $command): string
    {
        [$family, $name] = explode(':', $command, 2);

        return 'swamp/proof/'.$family.'/'.str_replace(':', '-', $name).'.cases.yaml';
    }

    /**
     * @param  array<string,string>  $commands  each selected command with the first path that selected it
     * @param  list<array{path:string,reason:string}>  $uncovered
     * @param  list<string>  $casesFiles
     */
    private static function byCommand(array $commands, array $uncovered, array $casesFiles, string $mergeBase): ProofSelectionResult
    {
        ksort($commands);
        $cases = [];
        foreach ($commands as $command => $path) {
            $file = self::fileOf($command);
            if (in_array($file, $casesFiles, true)) {
                $cases[] = ['command' => $command, 'file' => $file];
            } else {
                $uncovered[] = ['path' => $path, 'reason' => 'no cases file for '.$command];
            }
        }

        return new ProofSelectionResult($cases, $uncovered, null, $mergeBase);
    }

    /** The label of a file that a wildcard selected: its family and its name. A file name cannot tell a `-` from a `:`, so the name keeps its dashes. */
    private static function labelOf(string $file): string
    {
        [$family, $name] = explode('/', substr($file, strlen('swamp/proof/')), 2);

        return $family.':'.substr($name, 0, -strlen('.cases.yaml'));
    }
}
