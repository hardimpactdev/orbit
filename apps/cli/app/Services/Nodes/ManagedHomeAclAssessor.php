<?php

declare(strict_types=1);

namespace App\Services\Nodes;

/**
 * Evaluates whether a managed home POSIX ACL set is only the narrow Agent
 * traversal exception (u:agent:--x with mask --x).
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class ManagedHomeAclAssessor
{
    public function __construct(
        private string $managedAgentConsumer = 'agent',
    ) {}

    public function isManagedAgentTraversalOnly(string $getfaclOutput): bool
    {
        $entries = $this->parseEntries($getfaclOutput);

        if ($entries === null) {
            return false;
        }

        return $this->entriesAreManagedAgentTraversalOnly($entries);
    }

    /**
     * @return list<array{type: string, name: string, perms: string}>|null
     */
    private function parseEntries(string $output): ?array
    {
        $entries = [];
        $lines = preg_split('/\R/', $output);

        if (! is_array($lines)) {
            return null;
        }

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $line = trim((string) preg_replace(pattern: '/\s*#.*$/', replacement: '', subject: $line));

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'default:')) {
                return null;
            }

            $matches = null;

            if (preg_match('/^(user|group|mask|other):([^:]*):([rwx-]{3})$/', $line, $matches) !== 1) {
                return null;
            }

            $entries[] = [
                'type' => $matches[1],
                'name' => $matches[2],
                'perms' => $matches[3],
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{type: string, name: string, perms: string}>  $entries
     */
    private function entriesAreManagedAgentTraversalOnly(array $entries): bool
    {
        $ownerPerms = null;
        $groupPerms = null;
        $maskPerms = null;
        $otherPerms = null;
        $agentPerms = null;
        $namedUserEntries = 0;
        $namedGroupEntries = 0;

        foreach ($entries as $entry) {
            $type = $entry['type'];
            $name = $entry['name'];
            $perms = $entry['perms'];

            if ($type === 'user' && $name === '') {
                $ownerPerms = $perms;

                continue;
            }

            if ($type === 'group' && $name === '') {
                $groupPerms = $perms;

                continue;
            }

            if ($type === 'mask' && $name === '') {
                $maskPerms = $perms;

                continue;
            }

            if ($type === 'other' && $name === '') {
                $otherPerms = $perms;

                continue;
            }

            if ($type === 'user') {
                $namedUserEntries++;

                if ($name !== $this->managedAgentConsumer) {
                    return false;
                }

                $agentPerms = $perms;

                continue;
            }

            if ($type === 'group') {
                $namedGroupEntries++;

                continue;
            }

            return false;
        }

        return (
            $namedUserEntries === 1
            && $namedGroupEntries === 0
            && $agentPerms === '--x'
            && $ownerPerms === 'rwx'
            && $groupPerms === '---'
            && $otherPerms === '---'
            && $maskPerms === '--x'
        );
    }
}
