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

    /** Visible width available between the two side borders ("│ ... │"). */
    private const int INNER_WIDTH = self::PANEL_WIDTH - 4;

    /**
     * Family key to operator-facing category label (Category Catalog).
     *
     * @var array<string, string>
     */
    private const array CATEGORY_LABELS = [
        'node' => 'Node',
        'app' => 'Apps',
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
        'app' => ['label' => 'APP', 'keys' => ['app', 'slug', 'name']],
        'workspace' => ['label' => 'WORKSPACE', 'keys' => ['workspace', 'name']],
        'process' => ['label' => 'PROCESS', 'keys' => ['process', 'name', 'unit']],
        'proxy' => ['label' => 'DOMAIN', 'keys' => ['domain', 'host']],
        'firewall_rule' => ['label' => 'RULE', 'keys' => ['rule', 'name', 'key']],
        'tool' => ['label' => 'TOOL', 'keys' => ['tool', 'name', 'binary']],
        'schedule' => ['label' => 'SCHEDULE', 'keys' => ['schedule', 'name', 'command']],
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
    public function lines(array $report): array
    {
        $mode = $this->mode($report);
        $target = $this->target($report);
        $issuesByFamily = $this->issuesByFamily($report);
        $actionsByFamily = $this->actionsByFamily($report);
        $families = $this->renderedFamilies($report, $issuesByFamily, $actionsByFamily);
        $totalIssues = $this->totalIssues($report, $issuesByFamily);

        $lines = [];
        $lines[] = $this->dividerLine($this->title($mode), top: true);
        $lines[] = $this->blankLine();
        $lines[] = $this->dividerLine($this->targetLine($report, $target));
        $lines[] = $this->blankLine();

        foreach ($families as $family) {
            $familyIssues = $issuesByFamily[$family] ?? [];
            $lines[] = $this->categoryRow($family, $this->statusText($family, $familyIssues));

            if ($familyIssues !== []) {
                $lines = [...$lines, ...$this->issueTable($family, $familyIssues)];
            }

            $familyActions = $actionsByFamily[$family] ?? [];

            if ($mode !== 'verify' && $familyActions !== []) {
                $lines = [...$lines, ...$this->actionTable($familyActions)];
            }

            $lines[] = $this->blankLine();
        }

        $lines[] = $this->dividerLine('S U M M A R Y');
        $lines[] = $this->blankLine();

        foreach ($this->summaryLines($report, $mode, $totalIssues) as $summaryLine) {
            $lines[] = $this->centeredContentLine($summaryLine);
            $lines[] = $this->blankLine();
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

    private function title(string $mode): string
    {
        return match ($mode) {
            'restore' => 'D O C T O R  R E S T O R E',
            'adopt' => 'D O C T O R  A D O P T',
            'interactive' => 'D O C T O R  I N T E R A C T I V E',
            default => 'D O C T O R  R E S U L T',
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
    private function targetLine(array $report, string $target): string
    {
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
        $families = array_values(array_filter($families, static fn (mixed $family): bool => is_string($family) && $family !== ''));

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

        usort($families, static fn (string $a, string $b): int => (array_search($a, $order, true) ?: PHP_INT_MAX) <=> (array_search($b, $order, true) ?: PHP_INT_MAX));

        return $families;
    }

    /**
     * @param  list<array<string, mixed>>  $familyIssues
     */
    private function statusText(string $family, array $familyIssues): string
    {
        if ($familyIssues === []) {
            return 'OK';
        }

        $unverifiable = array_values(array_filter(
            $familyIssues,
            static fn (array $issue): bool => ($issue['kind'] ?? null) === 'unverifiable',
        ));

        if ($unverifiable !== [] && count($unverifiable) === count($familyIssues)) {
            $reason = $this->issueSummary($unverifiable[0]);

            return $reason === '' ? 'Unavailable' : "Unavailable, {$reason}";
        }

        $count = count($familyIssues);

        return $count === 1 ? '1 issue detected' : "{$count} issues found";
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
    private function summaryLines(array $report, string $mode, int $totalIssues): array
    {
        if ($mode === 'verify') {
            if ($totalIssues === 0) {
                return ['No issues detected'];
            }

            $count = $totalIssues === 1 ? '1 issue detected' : "{$totalIssues} issues detected";

            return [
                $count,
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

        if ($failed > 0) {
            return ["{$failed} actions failed; review remaining issues"];
        }

        if ($totalIssues === 0) {
            return ["No issues remaining; {$completed} actions completed"];
        }

        return ["{$totalIssues} issues remaining"];
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
                return trim($value);
            }
        }

        return 'doctor finding';
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

        if (str_starts_with($status, 'Unavailable')) {
            return SpinnerTreeRenderer::ORANGE;
        }

        return SpinnerTreeRenderer::RED;
    }

    private function categoryRow(string $family, string $status): string
    {
        $label = self::CATEGORY_LABELS[$family] ?? ucfirst($family);
        $dot = $this->statusColor($status).'●'.SpinnerTreeRenderer::RESET;
        $labelColumn = str_pad($label, 14);
        $content = "{$dot}  {$labelColumn}{$status}";

        return $this->contentLine($content, $this->visibleWidth("●  {$labelColumn}{$status}"));
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

                $row[$index] = $lineIndex === 0 ? ($cells[$index] ?? '') : '';
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

        for ($index = 0; $index < $columnCount - 1; $index++) {
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

        $line = SpinnerTreeRenderer::DIM.$left
            .str_repeat('─', $leftFill)
            .SpinnerTreeRenderer::RESET.SpinnerTreeRenderer::ACCENT.$spaced.SpinnerTreeRenderer::RESET
            .SpinnerTreeRenderer::DIM.str_repeat('─', $rightFill)
            .$right.SpinnerTreeRenderer::RESET;

        return $line;
    }

    private function bottomLine(): string
    {
        return SpinnerTreeRenderer::DIM
            .'└'.str_repeat('─', self::PANEL_WIDTH - 2).'┘'
            .SpinnerTreeRenderer::RESET;
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

        $leftPad = intdiv($available - $width, 2);
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

        return SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET
            .' '.$content.str_repeat(' ', $pad).' '
            .SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET;
    }

    /**
     * Wrap plain content (no surrounding padding spaces) flush against the
     * borders — used for tables that draw their own borders.
     */
    private function rawContentLine(string $content, int $visibleWidth): string
    {
        $pad = max(0, self::INNER_WIDTH + 2 - $visibleWidth);

        return SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET
            .$content.str_repeat(' ', $pad)
            .SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET;
    }

    private function visibleWidth(string $value): int
    {
        $stripped = preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $value) ?? $value;

        return mb_strlen($stripped);
    }
}
