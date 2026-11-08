<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** Reads the runner result. No YAML comparison belongs in the Gateway. */
final readonly class ProofCaseSelection
{
    /**
     * @param  array<string,mixed>|null  $result
     * @param  array{command:string,file:string}  $candidate
     * @return array<string,string>|null selected ids and reasons; null selects the whole file
     */
    public static function select(?array $result, string $base, string $head, array $candidate): ?array
    {
        if (($result['version'] ?? null) !== 1 || ($result['base'] ?? null) !== $base || ($result['head'] ?? null) !== $head
            || ! is_array($result['files'] ?? null) || ! array_is_list($result['files'])) {
            return null;
        }
        $matches = array_values(array_filter($result['files'], static fn (mixed $entry): bool => is_array($entry) && ($entry['file'] ?? null) === $candidate['file']));
        if (count($matches) !== 1) {
            return null;
        }
        $entry = $matches[0];
        $only = $entry['only'] ?? null;
        $reasons = $entry['reasons'] ?? null;
        if (($entry['command'] ?? null) !== $candidate['command'] || ! is_array($only) || ! array_is_list($only) || ! is_array($reasons)) {
            return null;
        }
        $selected = [];
        foreach ($only as $id) {
            if (! is_string($id) || preg_match('/\A[a-zA-Z0-9_-]+(?:\[[a-zA-Z0-9_-]+\])?\z/D', $id) !== 1
                || isset($selected[$id]) || ! is_string($reasons[$id] ?? null) || ! in_array($reasons[$id], [
                    'changed', 'added', 'uses changed fixture', 'uses changed var', 'batch setup changed',
                    'file settings changed', 'command code changed', 'proof tooling changed', 'selection uncertain',
                ], true)) {
                return null;
            }
            $selected[$id] = $reasons[$id];
        }

        return count($selected) === count($reasons) ? $selected : null;
    }
}
