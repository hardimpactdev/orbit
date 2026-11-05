<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Data\Nodes\NodeAgentData;
use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Support\ValidatedData;
use UnexpectedValueException;

/**
 * One family's checks on one Node: its findings, which checks ran, and which could not run for lack of a usable fact.
 * A check that cannot run makes the family unverifiable and keeps its codes out of the observed checks, so an old
 * Problem is never cleared by a check that did not run.
 */
final class DoctorFamilyInspection
{
    /** @var list<DoctorIssueData> */
    private array $issues = [];

    /** @var array<string, list<string>> */
    private array $checks = [];

    /** @var array<string, array<string, true>> */
    private array $blocked = [];

    private int $checked = 0;

    /** @param array<string, mixed> $facts the fact data by kind */
    public function __construct(
        public readonly DoctorFamily $family,
        public readonly Node $node,
        public readonly ?NodeAgentData $agent,
        private readonly array $facts,
        public readonly bool $managed,
        public readonly bool $parked,
    ) {}

    /**
     * The data of one fact.
     *
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException when the agent has not reported it yet
     */
    public function fact(NodeFactKind $kind): array
    {
        $data = $this->facts[$kind->value] ?? null;

        return is_array($data) ? ValidatedData::object($data) : throw new UnexpectedValueException("{$kind->value} not observed yet");
    }

    /**
     * One unit of the `units` fact, or null when the agent does not report it.
     *
     * @return array{state: string, load?: string, sub?: string}|null
     */
    public function unit(string $runtime, string $name): ?array
    {
        foreach (is_array($this->fact(NodeFactKind::Units)['units'] ?? null) ? $this->fact(NodeFactKind::Units)['units'] : [] as $unit) {
            if (is_array($unit) && ($unit['runtime'] ?? null) === $runtime && ($unit['name'] ?? null) === $name && is_string($unit['state'] ?? null)) {
                return array_filter(['state' => $unit['state'], 'load' => $unit['load'] ?? null, 'sub' => $unit['sub'] ?? null], is_string(...));
            }
        }

        return null;
    }

    public function issue(string $code, int|string $resourceId, string $resourceName, string $summary, ?string $expected = 'matching', ?string $observed = 'mismatch', DoctorIssueKind $kind = DoctorIssueKind::Drift): void
    {
        $this->issues[] = DoctorIssueData::fromInternal($this->family, $code, $kind, $resourceId, mb_substr($resourceName, 0, 255), mb_substr($summary, 0, 1000),
            $expected === null ? null : mb_substr($expected, 0, 255), $observed === null ? null : mb_substr($observed, 0, 255));
    }

    /** Records that these codes were checked on the resource, so a Problem for them can clear. */
    public function observed(int|string $resourceId, string ...$codes): void
    {
        $key = (string) $resourceId;
        $this->checks[$key] = array_values(array_unique([...$this->checks[$key] ?? [], ...$codes]));
    }

    /**
     * Runs one check. When its fact is missing or unusable, the check's codes are unverifiable on the resource.
     *
     * @param  list<string>  $codes  the codes this check tests
     * @param  callable(): void  $check
     */
    public function read(int|string $resourceId, array $codes, callable $check): void
    {
        $this->checked++;
        try {
            $check();
        } catch (UnexpectedValueException) {
            foreach ($codes as $code) {
                $this->blocked[(string) $resourceId][$code] = true;
            }
            $failed = $this->family->value.'.inspection_failed';
            if (! array_any($this->issues, static fn (DoctorIssueData $issue): bool => $issue->code === $failed)) {
                $this->issue($failed, "node:{$this->node->id}", $this->node->name, 'One or more checks have no usable fact from the Node agent.', 'verifiable', 'unverifiable', DoctorIssueKind::Unverifiable);
            }
        }
    }

    public function report(): DoctorFamilyReportData
    {
        $checks = [];
        foreach ($this->checks as $key => $codes) {
            $checks[$key] = array_values(array_filter($codes, fn (string $code): bool => ! isset($this->blocked[$key][$code])));
        }
        if ($this->checked > 0 && $this->blocked === []) {
            $checks["node:{$this->node->id}"] = [...$checks["node:{$this->node->id}"] ?? [], $this->family->value.'.inspection_failed'];
        }

        return DoctorFamilyReportData::fromIssues($this->family, $this->checked, $this->issues, [], $checks);
    }
}
