<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;

/** The immutable provision pin has one validation rule for publication and admission. */
final class TaskAnalysisBase
{
    /** @return array{base: string, instance_id: int, repository: string, branch: string}|null */
    public static function fromRecord(mixed $record, int $instanceId): ?array
    {
        if (! is_array($record) || count($record) !== 4 || ! is_string($record['base'] ?? null)
            || preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $record['base']) !== 1
            || ! is_int($record['instance_id'] ?? null) || $record['instance_id'] < 1 || $record['instance_id'] !== $instanceId
            || ! is_string($record['repository'] ?? null) || ! GitRepositoryOrigin::isValid($record['repository'])
            || ! is_string($record['branch'] ?? null) || ! GitBranchName::isValid($record['branch'])) {
            return null;
        }

        return ['base' => $record['base'], 'instance_id' => $record['instance_id'], 'repository' => $record['repository'], 'branch' => $record['branch']];
    }
}
