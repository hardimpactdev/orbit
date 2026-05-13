<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Doctor\FixDoctorRequest;
use App\Http\Gateway\Requests\Doctor\RunDoctorRequest;
use App\Http\Gateway\Responses\Doctor\DoctorRunResponse;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScopeValidator;
use App\Services\Doctor\DoctorValidationFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('doctor
    {--app= : Limit to one app}
    {--workspace= : Limit to one workspace}
    {--node= : Target node name}
    {--self : Limit to the calling node identity}
    {--family=* : Scope to one or more state families}
    {--fix : Enter resolution mode}
    {--restore : Bulk restore gateway configuration to nodes}
    {--adopt : Bulk adopt node reality into gateway configuration}
    {--json : Output JSON}')]
#[Description('Check Orbit health and diagnose drift')]
class DoctorCommand extends Command implements Loggable
{
    use LogsCommandActivity;

    private ?string $activityMode = null;

    /**
     * @var list<string>
     */
    private array $activityFamilies = [];

    private ?bool $activityHealthy = null;

    private ?int $activityIssues = null;

    public function handle(DoctorReportRunner $runner, DoctorScopeValidator $validator): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeDoctor($runner, $validator);
        } finally {
            $this->finishActivityLog();
        }
    }

    private function executeDoctor(DoctorReportRunner $runner, DoctorScopeValidator $validator): int
    {
        $mode = $this->mode();
        $families = $this->families();
        $this->activityMode = $mode;

        $resolutionFlags = array_values(array_filter([
            (bool) $this->option('fix') ? 'fix' : null,
            (bool) $this->option('restore') ? 'restore' : null,
            (bool) $this->option('adopt') ? 'adopt' : null,
        ]));

        if (count($resolutionFlags) > 1) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--fix, --restore, and --adopt are mutually exclusive.',
                meta: ['fields' => $resolutionFlags],
            );
        }

        if ((bool) $this->option('self') && $this->stringOption('node') !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--self and --node are mutually exclusive.',
                meta: ['fields' => ['self', 'node']],
            );
        }

        $target = $this->resolveTargetNode();
        $isGatewayCaller = $this->isGatewayCaller();

        if ($isGatewayCaller && $target === null) {
            $requestedNode = $this->stringOption('node');

            if ($requestedNode !== null) {
                return $this->failCommand(
                    code: 'scope_not_found',
                    message: "Node '{$requestedNode}' could not be resolved.",
                    meta: ['node' => $requestedNode],
                );
            }

            $target = Node::query()->where('role', 'gateway')->where('status', 'active')->first();
        }

        if ($target instanceof Node) {
            $this->activityFamilies = $families === [] ? $runner->categoriesForRole((string) $target->role) : $families;

            $failure = $validator->validate($families, $runner, $target);

            if ($failure instanceof DoctorValidationFailure) {
                return $this->failCommand($failure->code, $failure->message, $failure->meta);
            }

            $renderedFamilies = $families === [] ? $runner->categoriesForRole((string) $target->role) : $families;

            if (! $this->wantsJson()) {
                $this->renderDoctoringPanel($target, $renderedFamilies);
            }
        } else {
            $this->activityFamilies = $families;
        }

        $result = $isGatewayCaller && $target instanceof Node
            ? $this->runLocalDoctor($runner, $target, $mode, $families)
            : $this->runGatewayDoctor($runner, $target, $mode, $families);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to run doctor.',
                meta: $result->errorMeta(),
            );
        }

        if (($result['healthy'] ?? false) === true) {
            $this->recordActivityResult($result);

            if ($this->wantsJson()) {
                return $this->jsonSuccess(['doctor' => $result]);
            }

            $this->renderHuman($result);

            return self::SUCCESS;
        }

        $this->recordActivityResult($result);

        if (! $this->wantsJson()) {
            $this->renderHuman($result);

            return self::FAILURE;
        }

        return $this->failCommand(
            code: 'drift_detected',
            message: 'Doctor detected drift.',
            meta: [],
            data: ['doctor' => $result],
        );
    }

    public function effect(): ActivityLogType
    {
        if (in_array($this->activityMode, ['interactive', 'restore', 'adopt'], true)) {
            return ActivityLogType::Write;
        }

        return ActivityLogType::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'mode' => $this->activityMode,
            'families' => $this->activityFamilies,
            'healthy' => $this->activityHealthy,
            'issues' => $this->activityIssues,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function description(): ?string
    {
        return 'Doctor verification run';
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function runLocalDoctor(DoctorReportRunner $runner, Node $target, string $mode, array $families): array
    {
        if ($mode === 'verify') {
            return $runner->probe($target, families: $families);
        }

        if ($mode !== 'interactive') {
            return $runner->run($target, mode: $mode, families: $families);
        }

        $probe = $runner->probe($target, families: $families);
        $selected = $this->promptDoctorIssues($probe);
        $actions = [];

        foreach (['restore', 'adopt'] as $resolutionMode) {
            $issues = $selected[$resolutionMode] ?? [];

            if ($issues === []) {
                continue;
            }

            $actions = [
                ...$actions,
                ...$runner->apply($target, $resolutionMode, $issues),
            ];
        }

        return $runner->finalize($probe, 'interactive', $actions);
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>|GatewayApiException
     */
    private function runGatewayDoctor(DoctorReportRunner $runner, ?Node $target, string $mode, array $families): array|GatewayApiException
    {
        try {
            if ($mode === 'verify') {
                $dto = app(GatewayConnector::class)
                    ->send($this->gatewayRunRequest($target, $families))
                    ->dto();

                /** @var DoctorRunResponse $dto */
                return $dto->doctor;
            }

            if ($mode !== 'interactive') {
                $dto = app(GatewayConnector::class)
                    ->send($this->gatewayFixRequest($target, $mode, $families))
                    ->dto();

                /** @var DoctorRunResponse $dto */
                return $dto->doctor;
            }

            $probeDto = app(GatewayConnector::class)
                ->send($this->gatewayRunRequest($target, $families))
                ->dto();

            /** @var DoctorRunResponse $probeDto */
            $probe = $probeDto->doctor;
            $selected = $this->promptDoctorIssues($probe);
            $actions = [];

            foreach (['restore', 'adopt'] as $resolutionMode) {
                $issues = $selected[$resolutionMode] ?? [];

                if ($issues === []) {
                    continue;
                }

                $fixDto = app(GatewayConnector::class)
                    ->send($this->gatewayFixRequest($target, $resolutionMode, $families, $issues))
                    ->dto();

                /** @var DoctorRunResponse $fixDto */
                $doctor = $fixDto->doctor;
                $doctorActions = is_array($doctor['actions'] ?? null) ? array_values(array_filter($doctor['actions'], is_array(...))) : [];
                $actions = [...$actions, ...$doctorActions];
            }

            return $runner->finalize($probe, 'interactive', $actions);
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to run doctor.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }
    }

    /**
     * @param  list<string>  $families
     */
    private function gatewayRunRequest(?Node $target, array $families): RunDoctorRequest
    {
        return new RunDoctorRequest(
            families: $families,
            node: $this->gatewayTargetNodeName($target),
            self: $this->shouldForwardSelf($target),
            app: $this->stringOption('app'),
            workspace: $this->stringOption('workspace'),
        );
    }

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>|null  $issues
     */
    private function gatewayFixRequest(?Node $target, string $mode, array $families, ?array $issues = null): FixDoctorRequest
    {
        return new FixDoctorRequest(
            mode: $mode,
            families: $families,
            issues: $issues,
            node: $this->gatewayTargetNodeName($target),
            self: $this->shouldForwardSelf($target),
            app: $this->stringOption('app'),
            workspace: $this->stringOption('workspace'),
        );
    }

    private function shouldForwardSelf(?Node $target): bool
    {
        if ((bool) $this->option('self')) {
            return true;
        }

        // No explicit or default target: ask the gateway to resolve the caller.
        return $target === null && $this->gatewayTargetNodeName($target) === null;
    }

    private function gatewayTargetNodeName(?Node $target): ?string
    {
        if ($target instanceof Node) {
            return $target->name;
        }

        $explicitNode = $this->stringOption('node');

        if ($explicitNode !== null) {
            return $explicitNode;
        }

        return $this->shouldUseLocalDefaultNode() ? $this->localDefaultNodeName() : null;
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return array{restore: list<array<string, mixed>>, adopt: list<array<string, mixed>>}
     */
    private function promptDoctorIssues(array $probe): array
    {
        $issues = $this->doctorList($probe, 'issues');
        $selected = [
            'restore' => [],
            'adopt' => [],
        ];

        if ($issues === [] || $this->wantsJson()) {
            return $selected;
        }

        foreach ($issues as $issue) {
            $choices = ['skip'];

            if (($issue['restorable'] ?? false) === true) {
                $choices[] = 'restore';
            }

            if (($issue['adoptable'] ?? false) === true) {
                $choices[] = 'adopt';
            }

            if (count($choices) === 1) {
                continue;
            }

            $answer = $this->choice(
                question: $this->doctorIssuePrompt($issue),
                choices: $choices,
                default: 'skip',
            );

            if ($answer === 'restore' || $answer === 'adopt') {
                $selected[$answer][] = $issue;
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function doctorIssuePrompt(array $issue): string
    {
        $family = $this->doctorString($issue['family'] ?? null);
        $node = $this->doctorHumanValue($issue['node'] ?? null);
        $key = $this->doctorString($issue['key'] ?? null);
        $subject = $this->doctorIssueSubject($issue);

        if ($subject !== '') {
            return "Resolve {$family} issue {$key} for {$subject} on {$node}?";
        }

        return "Resolve {$family} issue {$key} on {$node}?";
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function doctorIssueSubject(array $issue): string
    {
        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];

        foreach (['domain', 'route', 'workspace', 'app', 'process', 'tool', 'rule', 'schedule_key', 'schedule'] as $key) {
            $value = $detail[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function localNode(): ?Node
    {
        return Node::query()->where('role', 'gateway')->where('status', 'active')->first();
    }

    private function resolveTargetNode(): ?Node
    {
        if (! $this->isGatewayCaller()) {
            return null;
        }

        $name = $this->stringOption('node');

        if ($name !== null) {
            $node = Node::query()->where('name', $name)->first();

            return $node instanceof Node ? $node : null;
        }

        if ($this->shouldUseLocalDefaultNode()) {
            $defaultName = $this->localDefaultNodeName();

            if ($defaultName !== null) {
                $node = Node::query()->where('name', $defaultName)->first();

                return $node instanceof Node ? $node : null;
            }
        }

        return $this->localNode()
            ?? Node::query()->where('role', 'gateway')->where('status', 'active')->first()
            ?? Node::query()->first();
    }

    private function shouldUseLocalDefaultNode(): bool
    {
        return ! (bool) $this->option('self')
            && $this->stringOption('node') === null
            && ! $this->isGatewayCaller();
    }

    private function localDefaultNodeName(): ?string
    {
        $name = LocalNodeDefault::query()->value('default_node_name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function isGatewayCaller(): bool
    {
        return (bool) config('orbit.is_gateway', false);
    }

    private function mode(): string
    {
        if ((bool) $this->option('restore')) {
            return 'restore';
        }

        if ((bool) $this->option('adopt')) {
            return 'adopt';
        }

        if ((bool) $this->option('fix')) {
            return 'interactive';
        }

        return 'verify';
    }

    /**
     * @return list<string>
     */
    private function families(): array
    {
        $families = $this->option('family');

        if (! is_array($families)) {
            return [];
        }

        return array_values(array_filter($families, static fn (mixed $family): bool => is_string($family) && $family !== ''));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    private function renderHuman(array $doctor): void
    {
        foreach ($this->doctorPanelLines($doctor) as $line) {
            $this->line($line);
        }
    }

    /**
     * @param  list<string>  $families
     */
    private function renderDoctoringPanel(Node $target, array $families): void
    {
        $width = 80;
        $innerWidth = $width - 2;
        $targetName = $this->doctorHumanValue($target->name);
        $lines = [
            $this->doctorPanelRule('top', 'D O C T O R I N G', $width),
            $this->doctorPanelEmpty($innerWidth),
            $this->doctorPanelRule('middle', "Performing check-up on {$targetName}", $width),
            $this->doctorPanelEmpty($innerWidth),
        ];

        foreach ($families as $family) {
            $lines[] = $this->doctorPanelBullet($this->doctorFamilyLabel($family), 'Checking', $innerWidth);
            $lines[] = $this->doctorPanelEmpty($innerWidth);
        }

        $lines[] = $this->doctorPanelRule('middle', 'S U M M A R Y', $width);
        $lines[] = $this->doctorPanelEmpty($innerWidth);
        $lines[] = $this->doctorPanelCentered('Gathering doctor results', $innerWidth);
        $lines[] = $this->doctorPanelEmpty($innerWidth);
        $lines[] = $this->doctorPanelRule('bottom', null, $width);

        foreach ($lines as $line) {
            $this->line($line);
        }

        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $doctor
     * @return list<string>
     */
    private function doctorPanelLines(array $doctor): array
    {
        $width = 80;
        $innerWidth = $width - 2;
        $scope = is_array($doctor['scope'] ?? null) ? $doctor['scope'] : [];
        $node = $this->doctorHumanValue($scope['node'] ?? null);
        $issues = $this->doctorList($doctor, 'issues');
        $actions = $this->doctorList($doctor, 'actions');
        $families = $this->doctorFamiliesForPanel($doctor);

        $lines = [
            $this->doctorPanelRule('top', 'D O C T O R  R E S U L T', $width),
            $this->doctorPanelEmpty($innerWidth),
            $this->doctorPanelRule('middle', "Successfully performed check-up on {$node}", $width),
            $this->doctorPanelEmpty($innerWidth),
        ];

        foreach ($families as $family) {
            $familyIssues = array_values(array_filter($issues, fn (array $issue): bool => ($issue['family'] ?? null) === $family));
            $familyActions = array_values(array_filter($actions, fn (array $action): bool => ($action['family'] ?? null) === $family));
            $lines[] = $this->doctorPanelBullet(
                $this->doctorFamilyLabel($family),
                $this->doctorFamilyStatus($familyIssues, $familyActions),
                $innerWidth,
                $familyIssues === [],
            );

            if ($familyIssues !== []) {
                $lines = [
                    ...$lines,
                    ...$this->doctorPanelIssueTable($family, $familyIssues, $innerWidth),
                ];
            }

            if ($familyActions !== []) {
                $lines = [
                    ...$lines,
                    ...$this->doctorPanelActionTable($familyActions, $innerWidth),
                ];
            }

            $lines[] = $this->doctorPanelEmpty($innerWidth);
        }

        $lines[] = $this->doctorPanelRule('middle', 'S U M M A R Y', $width);
        $lines[] = $this->doctorPanelEmpty($innerWidth);
        $lines[] = $this->doctorPanelCentered($this->doctorPanelSummary($doctor), $innerWidth);
        $lines[] = $this->doctorPanelEmpty($innerWidth);
        $lines[] = $this->doctorPanelCentered('Run doctor --fix manually or through an LLM to resolve issues', $innerWidth);
        $lines[] = $this->doctorPanelEmpty($innerWidth);
        $lines[] = $this->doctorPanelRule('bottom', null, $width);

        return $lines;
    }

    private function doctorPanelRule(string $position, ?string $label, int $width): string
    {
        $left = match ($position) {
            'top' => '┌',
            'bottom' => '└',
            default => '├',
        };
        $right = match ($position) {
            'top' => '┐',
            'bottom' => '┘',
            default => '┤',
        };

        if ($label === null || $label === '') {
            return $left.str_repeat('─', $width - 2).$right;
        }

        $remaining = max(2, $width - mb_strlen($label) - 6);
        $before = intdiv($remaining, 2);
        $after = $remaining - $before;

        return $left.str_repeat('─', $before).'  '.$label.'  '.str_repeat('─', $after).$right;
    }

    private function doctorPanelEmpty(int $innerWidth): string
    {
        return '│'.str_repeat(' ', $innerWidth).'│';
    }

    private function doctorPanelCentered(string $text, int $innerWidth): string
    {
        $text = mb_strimwidth($text, 0, $innerWidth, '…');
        $padding = $innerWidth - mb_strlen($text);
        $left = intdiv($padding, 2);
        $right = $padding - $left;

        return '│'.str_repeat(' ', $left).$text.str_repeat(' ', $right).'│';
    }

    private function doctorPanelBullet(string $label, string $status, int $innerWidth, ?bool $isHealthy = null): string
    {
        $left = '●  '.str_pad($label, 14);
        $text = $left.$status;
        $padWidth = $innerWidth + 1;
        $text = mb_strimwidth($text, 0, $padWidth, '…');
        $padded = $text.str_repeat(' ', max(0, $padWidth - mb_strlen($text))).'│';

        if ($isHealthy === false) {
            $padded = preg_replace('/^●/u', '<fg=red>●</>', $padded, 1);
        } elseif ($isHealthy === true) {
            $padded = preg_replace('/^●/u', '<fg=green>●</>', $padded, 1);
        }

        return (string) $padded;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<string>
     */
    private function doctorPanelIssueTable(string $family, array $issues, int $innerWidth): array
    {
        if ($family === 'node') {
            $summaries = array_map(
                fn (array $issue): string => $this->doctorString($issue['summary'] ?? null),
                $issues,
            );

            return $this->doctorPanelListTable($summaries, $innerWidth);
        }

        $columns = $this->doctorIssueColumns($family);
        $widths = $this->doctorIssueColumnWidths($columns, $innerWidth);
        $lines = [$this->doctorPanelTableSeparator($widths, 'top')];
        $lines[] = $this->doctorPanelTableRow(array_map(strtoupper(...), array_column($columns, 'label')), $widths);
        $lines[] = $this->doctorPanelTableSeparator($widths, 'middle');

        foreach ($issues as $issue) {
            $values = array_map(
                fn (array $column): string => $this->doctorString($this->doctorIssueColumnValue($column['key'], $issue)),
                $columns,
            );
            $lines[] = $this->doctorPanelTableRow($values, $widths);
        }

        $lines[] = $this->doctorPanelTableSeparator($widths, 'bottom');

        return $lines;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function doctorPanelListTable(array $items, int $innerWidth): array
    {
        $contentWidth = $innerWidth - 2;
        $widths = [$contentWidth];
        $lines = [$this->doctorPanelTableSeparator($widths, 'top')];

        foreach ($items as $item) {
            $entry = '- '.$item;
            $entry = mb_strimwidth($entry, 0, $contentWidth, '…');
            $lines[] = '│  '.str_pad($entry, $contentWidth).'│';
        }

        $lines[] = $this->doctorPanelTableSeparator($widths, 'bottom');

        return $lines;
    }

    /**
     * @return list<array{key: string, label: string, weight: int}>
     */
    private function doctorIssueColumns(string $family): array
    {
        return match ($family) {
            'node' => [
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 1],
            ],
            'app' => [
                ['key' => 'app', 'label' => 'App', 'weight' => 1],
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 4],
            ],
            'workspace' => [
                ['key' => 'workspace', 'label' => 'Workspace', 'weight' => 2],
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 5],
            ],
            'process' => [
                ['key' => 'app', 'label' => 'App', 'weight' => 1],
                ['key' => 'process', 'label' => 'Process', 'weight' => 1],
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 4],
            ],
            'proxy' => [
                ['key' => 'domain', 'label' => 'Domain', 'weight' => 2],
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 5],
            ],
            'firewall_rule' => [
                ['key' => 'rule', 'label' => 'Rule', 'weight' => 2],
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 5],
            ],
            'tool' => [
                ['key' => 'tool', 'label' => 'Tool', 'weight' => 2],
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 5],
            ],
            'schedule' => [
                ['key' => 'schedule', 'label' => 'Schedule', 'weight' => 2],
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 5],
            ],
            default => [
                ['key' => 'summary', 'label' => 'Issue', 'weight' => 1],
            ],
        };
    }

    /**
     * @param  list<array{key: string, label: string, weight: int}>  $columns
     * @return list<int>
     */
    private function doctorIssueColumnWidths(array $columns, int $innerWidth): array
    {
        $columnCount = count($columns);
        $padding = $columnCount * 2;
        $separators = max(0, $columnCount - 1);
        $available = max($columnCount, $innerWidth - $padding - $separators);
        $totalWeight = max(1, array_sum(array_column($columns, 'weight')));
        $widths = [];

        foreach ($columns as $column) {
            $widths[] = max(1, intdiv($available * $column['weight'], $totalWeight));
        }

        $remainder = $available - array_sum($widths);

        if ($remainder > 0) {
            $widths[count($widths) - 1] += $remainder;
        }

        return $widths;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function doctorIssueColumnValue(string $key, array $issue): mixed
    {
        if ($key === 'summary' || $key === 'kind' || $key === 'family' || $key === 'node' || $key === 'key') {
            return $issue[$key] ?? null;
        }

        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];

        if (array_key_exists($key, $detail)) {
            return $detail[$key];
        }

        if ($key === 'app') {
            return $issue['app'] ?? $detail['app'] ?? null;
        }

        if ($key === 'workspace') {
            return $issue['key'] ?? null;
        }

        if ($key === 'process') {
            return $issue['key'] ?? null;
        }

        return $issue[$key] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<string>
     */
    private function doctorPanelActionTable(array $actions, int $innerWidth): array
    {
        $widths = $this->doctorActionColumnWidths($innerWidth);
        $lines = [$this->doctorPanelTableSeparator($widths, 'top')];
        $lines[] = $this->doctorPanelTableRow(['ACTION', 'STATUS', 'KEY', 'SUMMARY'], $widths);
        $lines[] = $this->doctorPanelTableSeparator($widths, 'middle');

        foreach ($actions as $action) {
            $lines[] = $this->doctorPanelTableRow([
                $this->doctorString($action['mode'] ?? null),
                $this->doctorString($action['status'] ?? null),
                $this->doctorString($action['key'] ?? $action['code'] ?? null),
                $this->doctorString($action['summary'] ?? null),
            ], $widths);
        }

        $lines[] = $this->doctorPanelTableSeparator($widths, 'bottom');

        return $lines;
    }

    /**
     * @return list<int>
     */
    private function doctorActionColumnWidths(int $innerWidth): array
    {
        $fixed = [8, 10, 22];
        $padding = 4 * 2;
        $separators = 3;
        $remaining = max(8, $innerWidth - array_sum($fixed) - $padding - $separators);

        return [...$fixed, $remaining];
    }

    /**
     * @param  list<int>  $widths
     */
    private function doctorPanelTableSeparator(array $widths, string $position): string
    {
        $join = match ($position) {
            'top' => '┬',
            'middle' => '┼',
            'bottom' => '┴',
            default => '┼',
        };
        $segments = array_map(static fn (int $w): string => str_repeat('─', $w + 2), $widths);

        return '├'.implode($join, $segments).'┤';
    }

    /**
     * @param  list<string>  $values
     * @param  list<int>  $widths
     */
    private function doctorPanelTableRow(array $values, array $widths): string
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $cellWidth = $widths[$index] ?? 10;
            $value = mb_strimwidth($value, 0, $cellWidth, '…');
            $cells[] = ' '.str_pad($value, $cellWidth).' ';
        }

        return '│'.implode('│', $cells).'│';
    }

    /**
     * @param  array<string, mixed>  $doctor
     * @return list<string>
     */
    private function doctorFamiliesForPanel(array $doctor): array
    {
        $scope = is_array($doctor['scope'] ?? null) ? $doctor['scope'] : [];
        $families = is_array($scope['families'] ?? null) ? array_values(array_filter($scope['families'], is_string(...))) : [];

        return $families === [] ? ['node'] : $families;
    }

    private function doctorFamilyLabel(string $family): string
    {
        return match ($family) {
            'node' => 'Node',
            'app' => 'Apps',
            'workspace' => 'Workspaces',
            'process' => 'Processes',
            'proxy' => 'Proxy routes',
            'firewall_rule' => 'Firewall',
            'tool' => 'Tools',
            'schedule' => 'Scheduling',
            default => $family,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $actions
     */
    private function doctorFamilyStatus(array $issues, array $actions): string
    {
        if ($issues !== []) {
            return count($issues) === 1 ? '1 issue detected' : count($issues).' issues found';
        }

        if ($actions !== []) {
            return count($actions) === 1 ? '1 action completed' : count($actions).' actions completed';
        }

        return 'OK';
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    private function doctorPanelSummary(array $doctor): string
    {
        $summary = is_array($doctor['summary'] ?? null) ? $doctor['summary'] : [];
        $issueCount = (int) ($summary['issues'] ?? 0);

        if ($issueCount === 0) {
            return 'No issues detected';
        }

        return $issueCount === 1
            ? '1 issue detected'
            : "{$issueCount} issues detected";
    }

    /**
     * @param  array<string, mixed>  $doctor
     * @return list<array<string, mixed>>
     */
    private function doctorList(array $doctor, string $key): array
    {
        $items = $doctor[$key] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, is_array(...)));
    }

    private function doctorHumanValue(mixed $value): string
    {
        $string = $this->doctorString($value);

        return $string === '' ? '—' : $string;
    }

    private function doctorString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => (object) [],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function failCommand(string $code, string $message, array $meta, array $data = []): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                    'data' => empty($data) ? (object) [] : $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    private function recordActivityResult(array $doctor): void
    {
        $this->activityHealthy = (bool) ($doctor['healthy'] ?? false);
        $summary = is_array($doctor['summary'] ?? null) ? $doctor['summary'] : [];
        $this->activityIssues = (int) ($summary['issues'] ?? 0);
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (Throwable) {
            // Activity logging must not change the documented doctor result.
        }
    }
}
