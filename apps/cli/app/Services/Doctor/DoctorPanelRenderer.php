<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use Orbit\Core\Progress\SpinnerTreeRenderer;

/**
 * Renders the human framed-panel result for `orbit doctor`.
 *
 * The renderer is pure: it turns a doctor report payload (`data.doctor`) into a
 * list of pre-styled terminal lines. The command writes the lines. JSON output
 * is never produced here; the framed panel is human-only.
 *
 * Contract: apps/docs/content/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md
 */
final class DoctorPanelRenderer
{
    private const int PANEL_WIDTH = 80;

    private const int HUMAN_ISSUE_BULLET_CAP = 10;

    /** Visible width available between the two side borders ("│ ... │"). */
    private const int INNER_WIDTH = self::PANEL_WIDTH - 4;

    /** One space; contentLine() adds the second first-column space after the border. */
    private const int CONTENT_COLUMN_INDENT = 1;

    /**
     * Family key to operator-facing category label (Category Catalog).
     *
     * @var array<string, string>
     */
    private const array CATEGORY_LABELS = [
        'node' => 'Node',
        'instance' => 'Instances',
        'workspace' => 'Workspaces',
        'process' => 'Processes',
        'proxy' => 'Proxy routes',
        'firewall_rule' => 'Firewall',
        'tool' => 'Tools',
        'schedule' => 'Scheduling',
        'database_connection' => 'Databases',
    ];

    /**
     * Preferred entity column for each non-node family and the ordered list of
     * issue detail keys that resolve its value. The node family uses a single
     * `Issue` column, so it is intentionally absent here.
     *
     * @var array<string, array{label: string, keys: list<string>}>
     */
    private const array ENTITY_COLUMNS = [
        'instance' => ['label' => 'INSTANCE', 'keys' => ['instance', 'app', 'slug', 'name']],
        'workspace' => ['label' => 'WORKSPACE', 'keys' => ['workspace', 'name']],
        'process' => ['label' => 'PROCESS', 'keys' => ['process', 'name', 'unit']],
        'proxy' => ['label' => 'DOMAIN', 'keys' => ['domain', 'host']],
        'firewall_rule' => ['label' => 'RULE', 'keys' => ['rule', 'name', 'key']],
        'tool' => ['label' => 'TOOL', 'keys' => ['tool', 'name', 'binary']],
        'schedule' => ['label' => 'SCHEDULE', 'keys' => ['schedule', 'schedule_key', 'name', 'command']],
        'database_connection' => [
            'label' => 'CONNECTION',
            'keys' => ['connection', 'database_connection', 'slug', 'name', 'env_prefix'],
        ],
    ];

    /**
     * Resource labels used by verify-mode issue bullets.
     *
     * @var array<string, array{label: string, keys: list<string>}>
     */
    private const array ISSUE_RESOURCE_LABELS = [
        'instance' => ['label' => 'Instance', 'keys' => ['instance', 'app', 'slug', 'name']],
        'workspace' => ['label' => 'Workspace', 'keys' => ['workspace', 'name']],
        'process' => ['label' => 'Process', 'keys' => ['process', 'name', 'unit']],
        'proxy' => ['label' => 'Proxy route', 'keys' => ['domain', 'host', 'route']],
        'firewall_rule' => ['label' => 'Firewall rule', 'keys' => ['rule', 'name', 'key']],
        'tool' => ['label' => 'Tool', 'keys' => ['tool', 'name', 'binary']],
        'schedule' => ['label' => 'Schedule', 'keys' => ['schedule', 'schedule_key', 'name', 'command']],
        'database_connection' => [
            'label' => 'Database connection',
            'keys' => ['connection', 'database_connection', 'slug', 'name'],
        ],
    ];

    /**
     * Common issue-token expansions for human fallback summaries.
     *
     * @var array<string, string>
     */
    private const array ISSUE_WORD_REPLACEMENTS = [
        'api' => 'API',
        'ca' => 'CA',
        'cli' => 'CLI',
        'config' => 'configuration',
        'db' => 'database',
        'dns' => 'DNS',
        'env' => 'environment',
        'fpm' => 'FPM',
        'fs' => 'filesystem',
        'http' => 'HTTP',
        'https' => 'HTTPS',
        'id' => 'ID',
        'ip' => 'IP',
        'php' => 'PHP',
        'ssh' => 'SSH',
        'tls' => 'TLS',
        'tld' => 'TLD',
        'ufw' => 'UFW',
        'url' => 'URL',
        'wg' => 'WireGuard',
        'wireguard' => 'WireGuard',
    ];

    /**
     * The process family also carries an APP column before PROCESS.
     *
     * @var list<string>
     */
    private const array PROCESS_APP_KEYS = ['app', 'slug'];

    /** Issue-kind sort order within a category. */
    private const array KIND_ORDER = ['unverifiable', 'missing', 'divergent', 'extra'];

    /**
     * Render the final result panel.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public function lines(array $report, int $frame = 0): array
    {
        if ($this->isFleetReport($report)) {
            return $this->fleetLines($report, $frame);
        }

        return $this->singleNodeLines($report, $frame);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function singleNodeLines(array $report, int $frame = 0): array
    {
        $mode = $this->mode($report);
        $target = $this->target($report);
        $inProgress = $this->isInProgress($report);
        $issuesByFamily = $this->issuesByFamily($report);
        $actionsByFamily = $this->actionsByFamily($report);
        $families = $this->renderedFamilies($report, $issuesByFamily, $actionsByFamily);
        $totalIssues = $this->totalIssues($report, $issuesByFamily);

        $lines = [];
        $lines[] = $this->dividerLine($this->title($mode, $inProgress), top: true);
        $lines[] = $this->blankLine();
        $lines[] = $this->dividerLine($this->targetLine($report, $target, $inProgress));
        $lines[] = $this->blankLine();

        foreach ($families as $family) {
            $familyIssues = $issuesByFamily[$family] ?? [];
            $rendersIssueDetails = $mode === 'verify' && $familyIssues !== [];
            $checkProgress = $this->familyCheckProgress($report, $family);
            foreach ($this->categoryRows(
                $family,
                $this->statusText(
                    family: $family,
                    familyIssues: $familyIssues,
                    options: [
                        'progressStatus' => $this->familyProgressStatus($report, $family),
                        'mode' => $mode,
                        'detailsFollow' => $rendersIssueDetails,
                        'completed' => $checkProgress['completed'] ?? null,
                        'total' => $checkProgress['total'] ?? null,
                    ],
                ),
                $frame,
            ) as $categoryLine) {
                $lines[] = $categoryLine;
            }

            if ($familyIssues !== []) {
                $lines = [
                    ...$lines,
                    ...(
                        $rendersIssueDetails
                            ? $this->issueDetails($familyIssues)
                            : $this->issueTable($family, $familyIssues)
                    ),
                ];
            }

            $familyActions = $actionsByFamily[$family] ?? [];

            if ($mode !== 'verify' && $familyActions !== []) {
                $lines = [...$lines, ...$this->actionTable($familyActions)];
            }

            $lines[] = $this->blankLine();
        }

        if (! $inProgress) {
            $lines[] = $this->dividerLine('S U M M A R Y');
            $lines[] = $this->blankLine();

            foreach ($this->summaryLines($report, $mode, $totalIssues, false) as $summaryLine) {
                $lines[] = $this->centeredContentLine($summaryLine);
                $lines[] = $this->blankLine();
            }
        }

        $lines[] = $this->bottomLine();

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function fleetLines(array $report, int $frame = 0): array
    {
        $mode = $this->mode($report);
        $inProgress = $this->isInProgress($report);
        $issuesByNode = $this->issuesByNode($report);
        $nodes = $this->renderedFleetNodes($report);
        $totalIssues = $this->totalFleetIssues($report, $issuesByNode);

        $lines = [];
        $lines[] = $this->dividerLine($this->title($mode, $inProgress), top: true);
        $lines[] = $this->blankLine();
        $lines[] = $this->dividerLine($this->targetLine($report, 'fleet', $inProgress));
        $lines[] = $this->blankLine();

        foreach ($nodes as $nodeName) {
            $nodeIssues = $issuesByNode[$nodeName] ?? [];
            $rendersIssueDetails = $mode === 'verify' && $nodeIssues !== [];
            $nodeProgress = $this->nodeCheckProgress($report, $nodeName);
            $status = $this->fleetNodeStatusText(
                nodeIssues: $nodeIssues,
                options: [
                    'progressStatus' => $this->nodeProgressStatus($report, $nodeName),
                    'mode' => $mode,
                    'detailsFollow' => $rendersIssueDetails,
                    'completed' => $nodeProgress['completed'] ?? null,
                    'total' => $nodeProgress['total'] ?? null,
                ],
            );
            $status = $this->withFleetNodeRoles($status, $this->fleetNodeRoles($report, $nodeName));

            foreach ($this->fleetNodeRows(
                $nodeName,
                $status,
                $frame,
            ) as $categoryLine) {
                $lines[] = $categoryLine;
            }

            if ($nodeIssues !== [] && $rendersIssueDetails) {
                $lines = [...$lines, ...$this->issueDetails($nodeIssues)];
            }

            $lines[] = $this->blankLine();
        }

        if (! $inProgress) {
            $lines[] = $this->dividerLine('S U M M A R Y');
            $lines[] = $this->blankLine();

            $affectedFleetNodes = $this->affectedFleetNodeCount($issuesByNode);

            foreach ($this->summaryLines($report, $mode, $totalIssues, false, $affectedFleetNodes) as $summaryLine) {
                $lines[] = $this->centeredContentLine($summaryLine);
                $lines[] = $this->blankLine();
            }
        }

        $lines[] = $this->bottomLine();

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function mode(array $report): string
    {
        $mode = $report['mode'] ?? null;

        return match ($mode) {
            'restore' => 'restore',
            'adopt' => 'adopt',
            'interactive' => 'interactive',
            default => 'verify',
        };
    }

    private function title(string $mode, bool $inProgress): string
    {
        if ($inProgress) {
            return 'D O C T O R';
        }

        return match ($mode) {
            'restore' => 'D O C T O R  R E S T O R E',
            'adopt' => 'D O C T O R  A D O P T',
            'interactive' => 'D O C T O R  I N T E R A C T I V E',
            default => 'D O C T O R - R E S U L T',
        };
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function target(array $report): string
    {
        $scope = is_array($report['scope'] ?? null) ? $report['scope'] : [];
        $node = $scope['node'] ?? null;

        if (is_string($node) && trim($node) !== '') {
            return trim($node);
        }

        return 'this node';
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function targetLine(array $report, string $target, bool $inProgress): string
    {
        if ($inProgress) {
            return "Performing check-up on {$target}";
        }

        if (($report['healthy'] ?? false) === true) {
            return "Successfully performed check-up on {$target}";
        }

        if ($this->hasUnavailableProbe($report)) {
            return "Check-up on {$target} could not complete";
        }

        return "Successfully performed check-up on {$target}";
    }

    /**
     * Probe failures (unverifiable issues) mean a category could not complete.
     *
     * @param  array<string, mixed>  $report
     */
    private function hasUnavailableProbe(array $report): bool
    {
        return array_any($this->issues($report), fn ($issue) => ($issue['kind'] ?? null) === 'unverifiable');
    }

    /**
     * Resolve the rendered category set and its order. The report scope already
     * carries the role-derived, filter-intersected family order; fall back to
     * the families that own issues or actions when scope is absent.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, list<array<string, mixed>>>  $issuesByFamily
     * @param  array<string, list<array<string, mixed>>>  $actionsByFamily
     * @return list<string>
     */
    private function renderedFamilies(array $report, array $issuesByFamily, array $actionsByFamily): array
    {
        $scope = is_array($report['scope'] ?? null) ? $report['scope'] : [];
        $families = is_array($scope['families'] ?? null) ? $scope['families'] : [];
        $families = array_values(array_filter(
            $families,
            static fn (mixed $family): bool => is_string($family) && $family !== '',
        ));

        if ($families !== []) {
            return array_values(array_unique($families));
        }

        $derived = array_values(array_unique([
            ...array_keys($issuesByFamily),
            ...array_keys($actionsByFamily),
        ]));

        return $derived === [] ? ['node'] : $this->orderByCatalog($derived);
    }

    /**
     * @param  list<string>  $families
     * @return list<string>
     */
    private function orderByCatalog(array $families): array
    {
        $order = array_keys(self::CATEGORY_LABELS);

        usort(
            $families,
            static fn (string $a, string $b): int => (
                (array_search($a, $order, true) ?: PHP_INT_MAX) <=> (array_search($b, $order, true) ?: PHP_INT_MAX)
            ),
        );

        return $families;
    }

    /**
     * @param  list<array<string, mixed>>  $familyIssues
     * @param  array{progressStatus?: string|null, mode?: string, detailsFollow?: bool, completed?: int|null, total?: int|null}  $options
     */
    private function statusText(
        string $family,
        array $familyIssues,
        array $options = [],
    ): string {
        $progressStatus = $options['progressStatus'] ?? null;
        $mode = $options['mode'] ?? 'verify';
        $detailsFollow = $options['detailsFollow'] ?? false;
        $completed = $options['completed'] ?? null;
        $total = $options['total'] ?? null;

        if ($progressStatus !== null && ! in_array($progressStatus, ['done', 'ok'], true)) {
            return match ($progressStatus) {
                'queued' => 'Queued',
                'running', 'checking', 'start' => $mode === 'verify'
                    ? $this->checkingStatusText($completed, $total)
                    : $this->actionRunningText($mode),
                'gathering' => 'Gathering results',
                'restoring' => 'Restoring',
                'adopting' => 'Adopting',
                'skipped' => 'Skipped',
                default => $progressStatus,
            };
        }

        if ($familyIssues === []) {
            return 'OK';
        }

        $unverifiable = array_values(array_filter(
            $familyIssues,
            static fn (array $issue): bool => ($issue['kind'] ?? null) === 'unverifiable',
        ));

        if (! $detailsFollow && $unverifiable !== [] && count($unverifiable) === count($familyIssues)) {
            $reason = $this->issueSummary($unverifiable[0]);

            return $reason === '' ? 'Unavailable' : "Unavailable, {$reason}";
        }

        $count = count($familyIssues);

        $status = $count === 1 ? '1 issue detected' : "{$count} issues found";

        return $detailsFollow ? "{$status}:" : $status;
    }

    /**
     * Build verify-mode inline issue detail lines for a family.
     *
     * @param  list<array<string, mixed>>  $familyIssues
     * @return list<string>
     */
    private function issueDetails(array $familyIssues): array
    {
        $sorted = $this->sortIssues($familyIssues);
        $omitted = max(0, count($sorted) - self::HUMAN_ISSUE_BULLET_CAP);

        if ($omitted > 0) {
            $sorted = array_slice($sorted, 0, self::HUMAN_ISSUE_BULLET_CAP);
        }

        $lines = [$this->issueSeparatorLine()];
        $summaryWidth = self::INNER_WIDTH - self::CONTENT_COLUMN_INDENT - 2;

        foreach ($sorted as $issue) {
            foreach ($this->wrapText($this->issueBulletText($issue), $summaryWidth) as $index => $summaryLine) {
                $prefix = $index === 0 ? '- ' : '  ';
                $content = str_repeat(' ', self::CONTENT_COLUMN_INDENT).$prefix.$summaryLine;

                $lines[] = $this->contentLine($content, $this->visibleWidth($content));
            }
        }

        if ($omitted > 0) {
            $overflow = $omitted === 1 ? '+ 1 more issue' : "+ {$omitted} more issues";
            $content = str_repeat(' ', self::CONTENT_COLUMN_INDENT).$overflow;
            $lines[] = $this->contentLine($content, $this->visibleWidth($content));
        }

        return $lines;
    }

    private function issueSeparatorLine(): string
    {
        $dashWidth = self::INNER_WIDTH - self::CONTENT_COLUMN_INDENT - 1;
        $content =
            str_repeat(' ', self::CONTENT_COLUMN_INDENT)
            .SpinnerTreeRenderer::DIM
            .str_repeat('-', $dashWidth)
            .SpinnerTreeRenderer::RESET
            .' ';

        return $this->contentLine($content, self::INNER_WIDTH);
    }

    /**
     * Build the inline issue table lines for a family.
     *
     * @param  list<array<string, mixed>>  $familyIssues
     * @return list<string>
     */
    private function issueTable(string $family, array $familyIssues): array
    {
        $sorted = $this->sortIssues($familyIssues);

        if ($family === 'node') {
            $rows = array_map(fn (array $issue): array => [$this->issueSummary($issue)], $sorted);

            return $this->table(['ISSUE'], $rows, wrapLastColumn: true);
        }

        if ($family === 'process') {
            $rows = array_map(function (array $issue): array {
                $detail = $this->detail($issue);

                return [
                    $this->firstDetailValue($detail, self::PROCESS_APP_KEYS),
                    $this->firstDetailValue($detail, self::ENTITY_COLUMNS['process']['keys']),
                    $this->issueSummary($issue),
                ];
            }, $sorted);

            return $this->table(['APP', 'PROCESS', 'ISSUE'], $rows);
        }

        $column = self::ENTITY_COLUMNS[$family] ?? null;

        if ($column === null) {
            $rows = array_map(fn (array $issue): array => [$this->issueSummary($issue)], $sorted);

            return $this->table(['ISSUE'], $rows);
        }

        $rows = array_map(fn (array $issue): array => [
            $this->firstDetailValue($this->detail($issue), $column['keys']),
            $this->issueSummary($issue),
        ], $sorted);

        return $this->table([$column['label'], 'ISSUE'], $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $familyActions
     * @return list<string>
     */
    private function actionTable(array $familyActions): array
    {
        $rows = array_map(function (array $action): array {
            $status = is_string($action['status'] ?? null) ? $action['status'] : 'planned';
            $summary = is_string($action['summary'] ?? null) ? $action['summary'] : '';

            return [strtoupper($status), $summary];
        }, $familyActions);

        return $this->table(['ACTION', 'RESULT'], $rows);
    }

    /**
     * Sort issues by kind order, then preserve probe order.
     *
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function sortIssues(array $issues): array
    {
        $indexed = [];

        foreach ($issues as $position => $issue) {
            $indexed[] = ['position' => $position, 'issue' => $issue];
        }

        usort($indexed, function (array $a, array $b): int {
            $rankA = $this->kindRank($a['issue']);
            $rankB = $this->kindRank($b['issue']);

            return $rankA === $rankB
                ? $a['position'] <=> $b['position']
                : $rankA <=> $rankB;
        });

        return array_map(static fn (array $entry): array => $entry['issue'], $indexed);
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function kindRank(array $issue): int
    {
        $kind = is_string($issue['kind'] ?? null) ? $issue['kind'] : '';
        $rank = array_search($kind, self::KIND_ORDER, true);

        return $rank === false ? count(self::KIND_ORDER) : $rank;
    }

    /**
     * Compute the summary prose and next-action lines.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function summaryLines(
        array $report,
        string $mode,
        int $totalIssues,
        bool $inProgress,
        ?int $affectedFleetNodes = null,
    ): array {
        if ($inProgress) {
            if ($totalIssues === 0) {
                return ['No issues detected so far'];
            }

            return [$totalIssues === 1 ? '1 issue detected so far' : "{$totalIssues} issues detected so far"];
        }

        if ($mode === 'verify') {
            if ($totalIssues === 0) {
                return ['No issues detected'];
            }

            if ($affectedFleetNodes !== null) {
                $issueCount = $totalIssues === 1 ? '1 issue detected' : "{$totalIssues} issues detected";
                $nodeCount = $affectedFleetNodes === 1 ? '1 node' : "{$affectedFleetNodes} nodes";

                return [
                    "{$issueCount} among {$nodeCount}",
                    ...$this->dispositionSummaryLines($report),
                    'Run doctor --fix manually or through an LLM to resolve issues',
                ];
            }

            $count = $totalIssues === 1 ? '1 issue detected' : "{$totalIssues} issues detected";

            return [
                $count,
                ...$this->dispositionSummaryLines($report),
                'Run doctor --fix manually or through an LLM to resolve issues',
            ];
        }

        return $this->resolutionSummaryLines($report, $totalIssues);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function resolutionSummaryLines(array $report, int $totalIssues): array
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $failed = $this->intValue($summary, 'failed');
        $completed = $this->intValue($summary, 'fixed') + $this->intValue($summary, 'adopted');
        $lines = [];

        if ($failed > 0) {
            $lines[] = "{$failed} actions failed; review remaining issues";
        } elseif ($totalIssues === 0) {
            $lines[] = "No issues remaining; {$completed} actions completed";
        } else {
            $lines[] = "{$totalIssues} issues remaining";
        }

        $stopReason = is_string($summary['stop_reason'] ?? null) ? $summary['stop_reason'] : null;
        $passes = is_int($summary['passes'] ?? null) ? $summary['passes'] : null;

        if ($stopReason !== null && $passes !== null && $this->mode($report) === 'restore') {
            $lines[] = "Restore convergence: {$passes} pass(es), stop={$stopReason}";
        }

        return [
            ...$lines,
            ...$this->dispositionSummaryLines($report),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function dispositionSummaryLines(array $report): array
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $dispositions = is_array($summary['dispositions'] ?? null) ? $summary['dispositions'] : null;

        if ($dispositions === null) {
            $counts = [
                'genuine_drift' => 0,
                'blocked_inspection' => 0,
                'invalid_intent' => 0,
                'runtime_incident' => 0,
            ];

            foreach ($this->issues($report) as $issue) {
                $disposition = is_string($issue['disposition'] ?? null) ? $issue['disposition'] : '';

                if (array_key_exists($disposition, $counts)) {
                    $counts[$disposition]++;
                }
            }

            $dispositions = $counts;
        }

        $parts = [];

        foreach (['genuine_drift', 'blocked_inspection', 'invalid_intent', 'runtime_incident'] as $disposition) {
            $count = is_int($dispositions[$disposition] ?? null) ? $dispositions[$disposition] : 0;

            if ($count > 0) {
                $parts[] = "{$disposition}={$count}";
            }
        }

        if ($parts === []) {
            return [];
        }

        return ['Dispositions: '.implode(' ', $parts)];
    }

    private function actionRunningText(string $mode): string
    {
        return match ($mode) {
            'restore' => 'Restoring',
            'adopt' => 'Adopting',
            default => 'Checking',
        };
    }

    private function checkingStatusText(?int $completed, ?int $total): string
    {
        if ($completed !== null && $total !== null && $total > 0 && $completed < $total) {
            $percent = intdiv($completed * 100, $total);

            return "Checking - {$percent}%";
        }

        return 'Checking';
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, list<array<string, mixed>>>  $issuesByFamily
     */
    private function totalIssues(array $report, array $issuesByFamily): int
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

        if (array_key_exists('issues', $summary) && is_int($summary['issues'])) {
            return $summary['issues'];
        }

        $total = 0;

        foreach ($issuesByFamily as $familyIssues) {
            $total += count($familyIssues);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, list<array<string, mixed>>>
     */
    private function issuesByFamily(array $report): array
    {
        $grouped = [];

        foreach ($this->issues($report) as $issue) {
            $family = is_string($issue['family'] ?? null) ? $issue['family'] : 'node';
            $grouped[$family][] = $issue;
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, list<array<string, mixed>>>
     */
    private function issuesByNode(array $report): array
    {
        $grouped = [];

        foreach ($this->issues($report) as $issue) {
            $node = is_string($issue['node'] ?? null) && trim($issue['node']) !== ''
                ? trim($issue['node'])
                : 'unknown';
            $grouped[$node][] = $issue;
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function renderedFleetNodes(array $report): array
    {
        $nodes = $report['nodes'] ?? [];

        if (is_array($nodes) && $nodes !== []) {
            $names = [];

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $name = $node['node'] ?? null;

                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }

            if ($names !== []) {
                return $names;
            }
        }

        $scope = is_array($report['scope'] ?? null) ? $report['scope'] : [];
        $targets = is_array($scope['targets'] ?? null) ? $scope['targets'] : [];
        $names = [];

        foreach ($targets as $target) {
            if (! (is_string($target) && trim($target) !== '')) {
                continue;
            }

            $names[] = trim($target);
        }

        return $names;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $issuesByNode
     */
    private function affectedFleetNodeCount(array $issuesByNode): int
    {
        return count(array_filter(
            $issuesByNode,
            static fn (array $nodeIssues): bool => $nodeIssues !== [],
        ));
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $issuesByNode
     */
    private function totalFleetIssues(array $report, array $issuesByNode): int
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

        if (array_key_exists('issues', $summary) && is_int($summary['issues'])) {
            return $summary['issues'];
        }

        $total = 0;

        foreach ($issuesByNode as $nodeIssues) {
            $total += count($nodeIssues);
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $nodeIssues
     * @param  array{progressStatus?: string|null, mode?: string, detailsFollow?: bool, completed?: int|null, total?: int|null}  $options
     */
    private function fleetNodeStatusText(
        array $nodeIssues,
        array $options = [],
    ): string {
        $progressStatus = $options['progressStatus'] ?? null;
        $mode = $options['mode'] ?? 'verify';
        $detailsFollow = $options['detailsFollow'] ?? false;
        $completed = $options['completed'] ?? null;
        $total = $options['total'] ?? null;

        if ($progressStatus !== null && ! in_array($progressStatus, ['done', 'ok'], true)) {
            return match ($progressStatus) {
                'queued' => 'Queued',
                'running', 'checking', 'start' => $mode === 'verify'
                    ? $this->checkingStatusText($completed, $total)
                    : $this->actionRunningText($mode),
                'gathering' => 'Gathering results',
                default => ucfirst($progressStatus),
            };
        }

        if ($nodeIssues === []) {
            return 'OK';
        }

        $count = count($nodeIssues);
        $status = $count === 1 ? '1 issue detected' : "{$count} issues found";

        return $detailsFollow ? "{$status}:" : $status;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function isFleetReport(array $report): bool
    {
        $scope = is_array($report['scope'] ?? null) ? $report['scope'] : [];

        return ($scope['role'] ?? null) === 'fleet';
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, list<array<string, mixed>>>
     */
    private function actionsByFamily(array $report): array
    {
        $actions = $report['actions'] ?? [];
        $grouped = [];

        if (! is_array($actions)) {
            return $grouped;
        }

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $family = is_string($action['family'] ?? null) ? $action['family'] : 'node';
            $grouped[$family][] = $action;
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function issues(array $report): array
    {
        $issues = $report['issues'] ?? [];

        if (! is_array($issues)) {
            return [];
        }

        return array_values(array_filter($issues, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function detail(array $issue): array
    {
        $detail = $issue['detail'] ?? $issue['details'] ?? [];

        return is_array($detail) ? $detail : [];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  list<string>  $keys
     */
    private function firstDetailValue(array $detail, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $detail[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function issueSummary(array $issue): string
    {
        foreach (['summary', 'code', 'key'] as $key) {
            $value = $issue[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $summary = trim($value);

                return $this->looksLikeMachineIssueKey($summary, $issue)
                    ? $this->humanizedMachineIssueSummary($summary, $issue)
                    : $summary;
            }
        }

        return 'doctor finding';
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function issueBulletText(array $issue): string
    {
        $summary = $this->issueSummary($issue);
        $resource = $this->issueResourceLabel($issue);
        $disposition = $this->issueDispositionLabel($issue);
        $body = $summary;

        if ($resource !== '' && ! str_contains(strtolower($summary), strtolower($resource))) {
            $body = "{$resource}: {$summary}";
        }

        if ($disposition === '') {
            return $body;
        }

        return "[{$disposition}] {$body}";
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function issueDispositionLabel(array $issue): string
    {
        $disposition = is_string($issue['disposition'] ?? null) ? $issue['disposition'] : '';

        return match ($disposition) {
            'genuine_drift' => 'genuine_drift',
            'blocked_inspection' => 'blocked_inspection',
            'invalid_intent' => 'invalid_intent',
            'runtime_incident' => 'runtime_incident',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function looksLikeMachineIssueKey(string $summary, array $issue): bool
    {
        foreach (['code', 'key'] as $field) {
            $value = $issue[$field] ?? null;

            if (is_string($value) && trim($value) === $summary) {
                return true;
            }
        }

        return preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $summary) === 1;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function humanizedMachineIssueSummary(string $summary, array $issue): string
    {
        $family = is_string($issue['family'] ?? null) ? trim($issue['family']) : '';
        $suffix = $summary;

        if ($family !== '' && str_starts_with($suffix, "{$family}.")) {
            $suffix = substr($suffix, strlen($family) + 1);
        } elseif (str_contains($suffix, '.')) {
            $suffix = substr($suffix, strpos($suffix, '.') + 1);
        }

        $words = array_values(array_filter(
            preg_split('/[._]+/', $suffix) ?: [],
            static fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            return 'Doctor finding.';
        }

        $summary = implode(' ', array_map($this->issueWord(...), $words));

        return ucfirst($summary).'.';
    }

    private function issueWord(string $word): string
    {
        return self::ISSUE_WORD_REPLACEMENTS[$word] ?? $word;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function issueResourceLabel(array $issue): string
    {
        $family = is_string($issue['family'] ?? null) ? $issue['family'] : '';
        $detail = $this->detail($issue);
        $resource = self::ISSUE_RESOURCE_LABELS[$family] ?? null;

        if ($resource === null) {
            return '';
        }

        if ($family === 'database_connection') {
            return $this->databaseConnectionIssueResourceLabel($detail);
        }

        $value = $this->firstDetailValue($detail, $resource['keys']);

        if ($value === '') {
            return '';
        }

        if ($family !== 'process') {
            return "{$resource['label']} {$value}";
        }

        $app = $this->firstDetailValue($detail, self::PROCESS_APP_KEYS);

        return $app === ''
            ? "{$resource['label']} {$value}"
            : "{$resource['label']} {$value} for app {$app}";
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function databaseConnectionIssueResourceLabel(array $detail): string
    {
        $connection = $this->firstDetailValue($detail, self::ISSUE_RESOURCE_LABELS['database_connection']['keys']);

        if ($connection !== '') {
            return "Database connection {$connection}";
        }

        $envPrefix = $this->firstDetailValue($detail, ['env_prefix']);
        $workspace = $this->firstDetailValue($detail, ['workspace']);

        if ($workspace !== '') {
            return $envPrefix === ''
                ? "Database connection for workspace {$workspace}"
                : "Database connection {$envPrefix} for workspace {$workspace}";
        }

        $app = $this->firstDetailValue($detail, ['app']);

        if ($app === '') {
            return '';
        }

        return $envPrefix === ''
            ? "Database connection for app {$app}"
            : "Database connection {$envPrefix} for app {$app}";
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function isInProgress(array $report): bool
    {
        $progress = $report['progress'] ?? null;

        if (! is_array($progress)) {
            return false;
        }

        return ($progress['state'] ?? null) !== 'complete';
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function familyProgressStatus(array $report, string $family): ?string
    {
        $progress = $report['progress'] ?? null;

        if (! is_array($progress)) {
            return null;
        }

        $families = $progress['families'] ?? null;

        if (! is_array($families)) {
            return null;
        }

        foreach ($families as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['family'] ?? null) !== $family) {
                continue;
            }

            $status = $entry['status'] ?? null;

            return is_string($status) && trim($status) !== '' ? trim($status) : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function nodeProgressStatus(array $report, string $node): ?string
    {
        $progress = $report['progress'] ?? null;

        if (! is_array($progress)) {
            return null;
        }

        $nodes = $progress['nodes'] ?? null;

        if (! is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['node'] ?? null) !== $node) {
                continue;
            }

            $status = $entry['status'] ?? null;

            return is_string($status) && trim($status) !== '' ? trim($status) : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{completed: int, total: int}|null
     */
    private function nodeCheckProgress(array $report, string $node): ?array
    {
        $progress = $report['progress'] ?? null;

        if (! is_array($progress)) {
            return null;
        }

        $nodes = $progress['nodes'] ?? null;

        if (! is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['node'] ?? null) !== $node) {
                continue;
            }

            $completed = $entry['completed'] ?? null;
            $total = $entry['total'] ?? null;

            if (! is_int($completed) || ! is_int($total) || $total <= 0) {
                return null;
            }

            return [
                'completed' => $completed,
                'total' => $total,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{completed: int, total: int}|null
     */
    private function familyCheckProgress(array $report, string $family): ?array
    {
        $progress = $report['progress'] ?? null;

        if (! is_array($progress)) {
            return null;
        }

        $families = $progress['families'] ?? null;

        if (! is_array($families)) {
            return null;
        }

        foreach ($families as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['family'] ?? null) !== $family) {
                continue;
            }

            $completed = $entry['completed'] ?? null;
            $total = $entry['total'] ?? null;

            if (! is_int($completed) || ! is_int($total) || $total <= 0) {
                return null;
            }

            return [
                'completed' => $completed,
                'total' => $total,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function intValue(array $source, string $key): int
    {
        $value = $source[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    private function statusColor(string $status): string
    {
        if ($status === 'OK') {
            return SpinnerTreeRenderer::GREEN;
        }

        if (
            $status === 'Queued'
            || str_starts_with($status, 'Checking')
            || in_array($status, ['Gathering results', 'Restoring', 'Adopting'], true)
        ) {
            return SpinnerTreeRenderer::ACCENT;
        }

        if (str_starts_with($status, 'Unavailable')) {
            return SpinnerTreeRenderer::ORANGE;
        }

        return SpinnerTreeRenderer::RED;
    }

    /**
     * @return list<string>
     */
    private function fleetNodeRows(string $nodeName, string $status, int $frame): array
    {
        return $this->labeledStatusRows($nodeName, $status, $frame);
    }

    /**
     * Surface the complete active role set in the status column so fleet node
     * names stay stable while multi-role nodes remain visible without JSON.
     *
     * @param  list<string>  $roles
     */
    private function withFleetNodeRoles(string $status, array $roles): string
    {
        // Single-role rows stay compact; multi-role nodes surface the full
        // active set so operators are not limited to Node::displayRole().
        if (count($roles) < 2) {
            return $status;
        }

        $roleText = implode(', ', $roles);

        if (str_contains($status, $roleText)) {
            return $status;
        }

        return "{$status} · {$roleText}";
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function fleetNodeRoles(array $report, string $nodeName): array
    {
        $nodes = is_array($report['nodes'] ?? null) ? $report['nodes'] : [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['node'] ?? null) !== $nodeName) {
                continue;
            }

            $roles = $node['roles'] ?? null;

            if (is_array($roles)) {
                $normalized = [];

                foreach ($roles as $role) {
                    if (! (is_string($role) && $role !== '')) {
                        continue;
                    }

                    $normalized[] = $role;
                }

                return $normalized;
            }

            $primaryRole = $node['role'] ?? null;

            return (
                is_string($primaryRole) && $primaryRole !== '' && $primaryRole !== 'fleet'
                    ? [$primaryRole]
                    : []
            );
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function categoryRows(string $family, string $status, int $frame): array
    {
        $label = self::CATEGORY_LABELS[$family] ?? ucfirst($family);

        return $this->labeledStatusRows($label, $status, $frame);
    }

    /**
     * @return list<string>
     */
    private function labeledStatusRows(string $label, string $status, int $frame): array
    {
        $dot = $this->statusDot($status, $frame);
        $labelColumn = str_pad($label, 14);
        $prefix = "{$dot}  {$labelColumn}";
        $prefixWidth = $this->visibleWidth("●  {$labelColumn}");
        $statusWidth = max(1, self::PANEL_WIDTH - 1 - $prefixWidth);
        $statusLines = $this->wrapText($status, $statusWidth);
        $lines = [];

        foreach ($statusLines as $index => $statusLine) {
            $plainPrefix = $index === 0 ? "●  {$labelColumn}" : str_repeat(' ', $prefixWidth);
            $content = $index === 0 ? $prefix.$statusLine : $plainPrefix.$statusLine;
            $visibleContent = $plainPrefix.$statusLine;

            $lines[] = $this->flushRightBorderLine($content, $this->visibleWidth($visibleContent));
        }

        return $lines === [] ? [$this->flushRightBorderLine($prefix, $prefixWidth)] : $lines;
    }

    private function statusDot(string $status, int $frame): string
    {
        if (
            str_starts_with($status, 'Checking')
            || in_array($status, ['Gathering results', 'Restoring', 'Adopting'], true)
        ) {
            $frames = SpinnerTreeRenderer::spinnerFrames();

            return $frames[$frame % count($frames)];
        }

        if ($status === 'Queued') {
            return SpinnerTreeRenderer::DIM.'○'.SpinnerTreeRenderer::RESET;
        }

        return $this->statusColor($status).'●'.SpinnerTreeRenderer::RESET;
    }

    /**
     * Draw a compact bordered table that fits inside the panel.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function table(array $headers, array $rows, bool $wrapLastColumn = false): array
    {
        $columnCount = count($headers);
        $widths = [];

        foreach ($headers as $index => $header) {
            $widths[$index] = $this->visibleWidth($header);
        }

        $lastIndex = $columnCount - 1;

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                if ($index === $lastIndex && $wrapLastColumn) {
                    continue;
                }

                $widths[$index] = max($widths[$index] ?? 0, $this->visibleWidth($cell));
            }
        }

        // Available width for cells: inner width minus the borders/padding the
        // table draws ("│ " + " │ "... + " │"). Each column has 1 space padding
        // on each side and a separating "│".
        $available = self::INNER_WIDTH - ($columnCount * 3) - 1;
        $widths = $this->fitColumnWidths($widths, $available, $columnCount);

        $lines = [];
        $lines[] = $this->tableBorder($widths, '┌', '┬', '┐');
        $lines[] = $this->tableRow($headers, $widths);
        $lines[] = $this->tableBorder($widths, '├', '┼', '┤');

        foreach ($rows as $row) {
            if ($wrapLastColumn) {
                $lines = [...$lines, ...$this->wrappedRow($row, $widths, $lastIndex)];

                continue;
            }

            $lines[] = $this->tableRow($row, $widths);
        }

        $lines[] = $this->tableBorder($widths, '└', '┴', '┘');

        return $lines;
    }

    /**
     * Render a table row whose last column wraps across as many physical lines
     * as needed, padding the leading columns on the first line only.
     *
     * @param  list<string>  $cells
     * @param  array<int, int>  $widths
     * @return list<string>
     */
    private function wrappedRow(array $cells, array $widths, int $lastIndex): array
    {
        $lastWidth = $widths[$lastIndex] ?? self::INNER_WIDTH;
        $wrapped = $this->wrapText($cells[$lastIndex] ?? '', max(1, $lastWidth));
        $lines = [];

        foreach ($wrapped as $lineIndex => $segment) {
            $row = [];

            foreach ($widths as $index => $width) {
                if ($index === $lastIndex) {
                    $row[$index] = $segment;

                    continue;
                }

                $row[$index] = $lineIndex === 0 ? $cells[$index] ?? '' : '';
            }

            $lines[] = $this->tableRow($row, $widths);
        }

        return $lines;
    }

    /**
     * Wrap operator guidance to the given width. Sentences start on their own
     * line so multi-sentence node guidance keeps each instruction intact, then
     * each sentence word-wraps if it still overflows the column.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        $text = trim($text);

        if ($text === '') {
            return [''];
        }

        $lines = [];

        foreach ($this->splitSentences($text) as $sentence) {
            $lines = [...$lines, ...$this->wrapWords($sentence, $width)];
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
    private function wrapWords(string $text, int $width): array
    {
        $lines = [];

        foreach (preg_split('/\s+/', $text) ?: [] as $word) {
            if ($lines === []) {
                $lines[] = $word;

                continue;
            }

            $current = $lines[count($lines) - 1];

            if ($this->visibleWidth($current.' '.$word) <= $width) {
                $lines[count($lines) - 1] = $current.' '.$word;

                continue;
            }

            $lines[] = $word;
        }

        $expanded = [];

        foreach ($lines as $line) {
            while ($this->visibleWidth($line) > $width) {
                $expanded[] = mb_substr($line, 0, $width);
                $line = mb_substr($line, $width);
            }

            $expanded[] = $line;
        }

        return $expanded;
    }

    /**
     * Distribute the available width across columns, giving the trailing ISSUE
     * column the remaining space so summaries are not truncated unnecessarily.
     *
     * @param  array<int, int>  $widths
     * @return array<int, int>
     */
    private function fitColumnWidths(array $widths, int $available, int $columnCount): array
    {
        $entityTotal = 0;

        for ($index = 0; $index < ($columnCount - 1); $index++) {
            $widths[$index] = min($widths[$index] ?? 0, 28);
            $entityTotal += $widths[$index];
        }

        $last = $columnCount - 1;
        $remaining = max(8, $available - $entityTotal);
        $widths[$last] = max(4, $remaining);

        return $widths;
    }

    /**
     * @param  array<int, int>  $widths
     */
    private function tableBorder(array $widths, string $left, string $mid, string $right): string
    {
        $segments = array_map(static fn (int $width): string => str_repeat('─', $width + 2), $widths);
        $content = $left.implode($mid, $segments).$right;

        return $this->rawContentLine($content, $this->visibleWidth($content));
    }

    /**
     * @param  list<string>  $cells
     * @param  array<int, int>  $widths
     */
    private function tableRow(array $cells, array $widths): string
    {
        $rendered = [];

        foreach ($widths as $index => $width) {
            $cell = $cells[$index] ?? '';
            $rendered[] = ' '.$this->padOrTruncate($cell, $width).' ';
        }

        $content = '│'.implode('│', $rendered).'│';

        return $this->rawContentLine($content, $this->visibleWidth($content));
    }

    private function padOrTruncate(string $value, int $width): string
    {
        $length = $this->visibleWidth($value);

        if ($length === $width) {
            return $value;
        }

        if ($length < $width) {
            return $value.str_repeat(' ', $width - $length);
        }

        if ($width <= 1) {
            return mb_substr($value, 0, $width);
        }

        return mb_substr($value, 0, $width - 1).'…';
    }

    private function dividerLine(string $label, bool $top = false): string
    {
        $left = $top ? '┌' : '├';
        $right = $top ? '┐' : '┤';
        $spaced = "  {$label}  ";
        $remaining = self::PANEL_WIDTH - 2 - $this->visibleWidth($spaced);
        $remaining = max(0, $remaining);
        $leftFill = intdiv($remaining, 2);
        $rightFill = $remaining - $leftFill;

        $line =
            SpinnerTreeRenderer::DIM
            .$left
            .str_repeat('─', $leftFill)
            .SpinnerTreeRenderer::RESET
            .SpinnerTreeRenderer::ACCENT
            .$spaced
            .SpinnerTreeRenderer::RESET
            .SpinnerTreeRenderer::DIM
            .str_repeat('─', $rightFill)
            .$right
            .SpinnerTreeRenderer::RESET;

        return $line;
    }

    private function bottomLine(): string
    {
        return SpinnerTreeRenderer::DIM.'└'.str_repeat('─', self::PANEL_WIDTH - 2).'┘'.SpinnerTreeRenderer::RESET;
    }

    private function blankLine(): string
    {
        return $this->rawContentLine('', 0);
    }

    private function centeredContentLine(string $text): string
    {
        $width = $this->visibleWidth($text);
        $available = self::INNER_WIDTH;

        if ($width >= $available) {
            return $this->contentLine($text, $width);
        }

        $leftPad = max(0, intdiv($available - $width, 2));
        $padded = str_repeat(' ', $leftPad).$text;

        return $this->contentLine($padded, $leftPad + $width);
    }

    /**
     * Wrap pre-styled content (may contain ANSI) between the side borders,
     * right-padding to the panel width using the supplied visible width.
     */
    private function contentLine(string $content, int $visibleWidth): string
    {
        $pad = max(0, self::INNER_WIDTH - $visibleWidth);

        return (
            SpinnerTreeRenderer::DIM
            .'│'
            .SpinnerTreeRenderer::RESET
            .' '
            .$content
            .str_repeat(' ', $pad)
            .' '
            .SpinnerTreeRenderer::DIM
            .'│'
            .SpinnerTreeRenderer::RESET
        );
    }

    /**
     * Render category rows with the status dot in column 1 while preserving the
     * dim right border of the outer panel.
     */
    private function flushRightBorderLine(string $content, int $visibleWidth): string
    {
        $pad = max(0, self::PANEL_WIDTH - 1 - $visibleWidth);

        return $content.str_repeat(' ', $pad).SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET;
    }

    /**
     * Wrap plain content (no surrounding padding spaces) flush against the
     * borders — used for tables that draw their own borders.
     */
    private function rawContentLine(string $content, int $visibleWidth): string
    {
        $pad = max(0, self::INNER_WIDTH + 2 - $visibleWidth);

        return (
            SpinnerTreeRenderer::DIM
            .'│'
            .SpinnerTreeRenderer::RESET
            .$content
            .str_repeat(' ', $pad)
            .SpinnerTreeRenderer::DIM
            .'│'
            .SpinnerTreeRenderer::RESET
        );
    }

    private function visibleWidth(string $value): int
    {
        $stripped = preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $value) ?? $value;

        return mb_strlen($stripped);
    }
}
