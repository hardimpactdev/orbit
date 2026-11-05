<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Shared\ResourceOperationException;
use LogicException;

/**
 * Chooses the cases of a commit from its changed paths and its selection list
 * ([Choose the cases](/reference/feature-flow#choose-the-cases)). Pure: it reads nothing.
 */
final readonly class ProofSelection
{
    private const string ProgramPattern = '~\Aswamp/proof/([a-z][a-z0-9_]*)/cases/([A-Za-z0-9_]+-[A-Za-z0-9_.-]*)\.sh\z~D';

    /**
     * @param  list<string>  $treePaths  every path of the commit's tree under `swamp/proof`
     * @return list<string> the case programs, sorted
     */
    public static function programs(array $treePaths): array
    {
        $programs = array_values(array_filter($treePaths, static fn (string $path): bool => preg_match(self::ProgramPattern, $path) === 1));
        sort($programs);

        return $programs;
    }

    /**
     * @param  string|null  $list  the selection list of the commit, null when the commit has none
     * @param  list<string>  $programs  every case program of the commit
     *
     * @throws ResourceOperationException proof.selection_invalid, proof.path_unmapped
     */
    public static function select(?string $list, ProofChangedFiles $changes, bool $all, array $programs): ProofSelectionResult
    {
        $paths = $changes->paths();
        $everything = match (true) {
            $all => 'all_requested',
            in_array(ProofSelectionList::Path, $paths, true) => 'selection_list_changed',
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
            ? new ProofSelectionResult(array_map(static fn (string $program): array => ['program' => $program, 'command' => self::commandOf($program)], $programs), $uncovered, $everything)
            : self::byCommand($commands, $uncovered, $programs);
    }

    /**
     * @param  array<string,string>  $commands  each selected command with the first path that selected it
     * @param  list<array{path:string,reason:string}>  $uncovered
     * @param  list<string>  $programs
     */
    private static function byCommand(array $commands, array $uncovered, array $programs): ProofSelectionResult
    {
        ksort($commands);
        $cases = [];
        foreach ($commands as $command => $path) {
            $found = array_values(array_filter($programs, static fn (string $program): bool => self::programOf($command, $program)));
            if ($found === []) {
                $uncovered[] = ['path' => $path, 'reason' => 'no case program for '.$command];
            }
            foreach ($found as $program) {
                $cases[$program] = ['program' => $program, 'command' => $command];
            }
        }
        ksort($cases);

        return new ProofSelectionResult(array_values($cases), $uncovered, null);
    }

    /** The case programs of `family:a:b` are `swamp/proof/family/cases/a-b-*.sh`. */
    private static function programOf(string $command, string $program): bool
    {
        [$family, $name] = explode(':', $command, 2);

        return str_starts_with($program, 'swamp/proof/'.$family.'/cases/'.str_replace(':', '-', $name).'-');
    }

    /** The label of a program that a wildcard selected: its family and the first word of its file name. */
    private static function commandOf(string $program): string
    {
        if (preg_match(self::ProgramPattern, $program, $match) !== 1) {
            throw new LogicException('Not a case program: '.$program);
        }

        return $match[1].':'.explode('-', $match[2])[0];
    }
}
