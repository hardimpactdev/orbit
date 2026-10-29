<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
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
        if (! $composer && preg_match($range, trim($value)) !== 1 && ! in_array(strtolower($value), ['file', 'link', 'workspace', 'npm', 'git'], true) && preg_match('/\\A[a-zA-Z][a-zA-Z0-9_.-]*\\z/D', $value) === 1 && preg_match('/\.(?:tgz|tar\.gz)\z/iD', $value) !== 1 && ! str_starts_with($value, 'dev-')) {
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
        if ($before !== null && $after === null) {
            $this->invalid();
        }
        if ($base === $head || $before === null) {
            return;
        }
        $old = $this->majors($before);
        $new = $this->majors($after);
        if ($new === [] || $old === []) {
            $this->invalid();
        }
        if (array_slice($new[0], 0, 2) > array_slice($old[0], 0, 2)) {
            $this->major();
        }
        foreach ($new as [$axis, $start, $end]) {
            $cursor = $start;
            foreach ($old as [$oldAxis, $low, $high]) {
                if ($oldAxis !== $axis) {
                    continue;
                }
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
        if ($this->breakingVersion($base) !== $this->breakingVersion($head)) {
            $this->major();
        }
    }

    /** @return list<int> */
    private function breakingVersion(string $version): array
    {
        $parts = array_map(intval(...), explode('.', ltrim($version, 'v')));

        return $parts[0] > 0 ? [$parts[0]] : ($parts[1] > 0 ? [0, $parts[1]] : [0, 0, $parts[2]]);
    }

    /**
     * Project numeric intervals onto the semver breaking component, including 0.x.
     *
     * @return list<array{int,int,int}>
     */
    private function majors(ConstraintInterface $range): array
    {
        $result = [];
        foreach ([[0, 2, '0.0.0.0-dev', '0.1.0.0-dev'], [1, 1, '0.1.0.0-dev', '1.0.0.0-dev'], [2, 0, '1.0.0.0-dev', PHP_INT_MAX.'.0.0.0']] as [$axis, $component, $floor, $ceiling]) {
            $slice = new MultiConstraint([$range, new Constraint('>=', $floor), new Constraint('<', $ceiling)]);
            foreach (Intervals::get($slice)['numeric'] as $interval) {
                $start = (int) explode('.', $interval->getStart()->getVersion())[$component];
                $version = $interval->getEnd()->getVersion();
                $parts = explode('.', preg_replace('/-.*\z/', '', $version) ?? $version);
                $end = (int) $parts[$component];
                $boundary = count(array_filter(array_slice($parts, $component + 1), static fn (string $part): bool => (int) $part !== 0)) === 0;
                if ($interval->getEnd()->getOperator() === '<' && $boundary && str_ends_with($version, '-dev')) {
                    $end--;
                }
                // The ceiling leaves this component's domain rather than wrapping to zero.
                if ($version === $ceiling) {
                    $end = PHP_INT_MAX;
                }
                if ($end >= $start) {
                    $result[] = [$axis, $start, $end];
                }
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
