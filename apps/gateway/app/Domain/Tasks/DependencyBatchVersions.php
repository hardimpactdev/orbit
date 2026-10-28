<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

final readonly class DependencyBatchVersions
{
    public function constraint(string $value, bool $composer): ?ConstraintInterface
    {
        $part = '(?:[0-9]+|[xX*])';
        $version = '[vV]?'.$part.'(?:\\.'.$part.'){0,2}(?:-[0-9A-Za-z.-]+)?(?:\\+[0-9A-Za-z.-]+)?';
        $atom = '(?:[~^]|[<>!]=?|=)?\\s*'.$version;
        $range = '#\\A'.$atom.'(?:(?:\\s*\\|\\|\\s*|\\s*,\\s*|\\s+-\\s+|\\s+)'.$atom.')*\\z#D';
        if (! $composer && preg_match($range, trim($value)) !== 1 && ! in_array(strtolower($value), ['file', 'link', 'workspace', 'npm', 'git'], true) && preg_match('/\\A[a-zA-Z][a-zA-Z0-9_.-]*\\z/D', $value) === 1 && ! str_starts_with($value, 'dev-')) {
            return null;
        }
        if (preg_match('/[:\\/#@]/', $value) === 1 || stripos($value, 'dev') !== false || preg_match($range, trim($value)) !== 1) {
            $this->invalid();
        }
        try {
            return (new VersionParser)->parseConstraints($value);
        } catch (UnexpectedValueException) {
            $this->invalid();
        }
    }

    public function constraintChange(string $base, string $head, bool $composer): void
    {
        $before = $this->constraint($base, $composer);
        $after = $this->constraint($head, $composer);
        if ($base === $head || $before === null || $after === null) {
            return;
        }
        $old = $this->majors($before);
        $new = $this->majors($after);
        if ($new === [] || $old === []) {
            $this->invalid();
        }
        if ($new[0][0] > $old[0][0]) {
            $this->major();
        }
        foreach ($new as [$start, $end]) {
            $cursor = $start;
            foreach ($old as [$low, $high]) {
                if ($low > $cursor) {
                    break;
                }
                if ($high >= $end) {
                    continue 2;
                }
                if ($high >= $cursor) {
                    $cursor = $high + 1;
                }
            }
            $this->major();
        }
    }

    public function lockedChange(string $base, string $head): void
    {
        foreach ([$base, $head] as $version) {
            if (preg_match('/\\Av?([0-9]+)\\.[0-9]+\\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\\z/D', $version) !== 1) {
                $this->invalid();
            }
        }
        if ((int) ltrim($base, 'v') !== (int) ltrim($head, 'v')) {
            $this->major();
        }
    }

    /** @return list<array{int,int}> */
    private function majors(ConstraintInterface $range): array
    {
        $result = [];
        foreach (Intervals::get($range)['numeric'] as $interval) {
            $start = (int) $interval->getStart()->getVersion();
            $version = $interval->getEnd()->getVersion();
            $end = (int) $version;
            if ($end !== PHP_INT_MAX && $interval->getEnd()->getOperator() === '<' && preg_match('/\\A[0-9]+\\.0\\.0\\.0(?:-dev)?\\z/D', $version) === 1) {
                $end--;
            }
            if ($end >= $start) {
                $result[] = [$start, $end];
            }
        }

        return $result;
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('dependency_merge.contents_forbidden', 'Registry version constraints and exact locked versions are required.', 409);
    }

    private function major(): never
    {
        throw new ResourceOperationException('dependency_merge.major_bump', 'Major dependency changes require hand-testing; keep the PR open and development Instance active.', 409);
    }
}
