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
    {--restore : Bulk restore gateway intent to nodes (requires --fix)}
    {--adopt : Bulk adopt node reality into gateway (requires --fix)}
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
        $this->activityFamilies = $families === [] ? $runner->supportedFamilies() : $families;

        if ((bool) $this->option('restore') && (bool) $this->option('adopt')) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--restore and --adopt are mutually exclusive.',
                meta: ['fields' => ['restore', 'adopt']],
            );
        }

        if (! (bool) $this->option('fix') && ((bool) $this->option('restore') || (bool) $this->option('adopt'))) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--restore and --adopt require --fix.',
                meta: ['fields' => ['fix']],
            );
        }

        if ((bool) $this->option('self') && $this->stringOption('node') !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--self and --node are mutually exclusive.',
                meta: ['fields' => ['self', 'node']],
            );
        }

        if ($mode !== 'verify' && $this->callerRole() === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'App-node callers may not run doctor --fix for this scope.',
                meta: [
                    'caller_role' => 'app',
                    'mode' => $mode,
                ],
            );
        }

        $failure = $validator->validate($families, $runner);

        if ($failure instanceof DoctorValidationFailure) {
            return $this->failCommand($failure->code, $failure->message, $failure->meta);
        }

        if (! $this->wantsJson()) {
            $this->renderDoctoringPanel($families === [] ? $runner->supportedFamilies() : $families);
        }

        $result = $this->isGatewayCaller()
            ? $this->runLocalDoctor($runner, $mode, $families)
            : $this->runGatewayDoctor($runner, $mode, $families);

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
    private function runLocalDoctor(DoctorReportRunner $runner, string $mode, array $families): array
    {
        $node = $this->localNode() ?? Node::query()->where('role', 'gateway')->where('status', 'active')->first() ?? Node::query()->firstOrFail();

        if ($mode === 'verify') {
            return $runner->probe($node, families: $families);
        }

        if ($mode !== 'interactive') {
            return $runner->run($node, mode: $mode, families: $families);
        }

        $probe = $runner->probe($node, families: $families);
        $selected = $this->promptDoctorIssues($probe);
        $actions = [];

        foreach (['restore', 'adopt'] as $resolutionMode) {
            $issues = $selected[$resolutionMode] ?? [];

            if ($issues === []) {
                continue;
            }

            $actions = [
                ...$actions,
                ...$runner->apply($node, $resolutionMode, $issues),
            ];
        }

        return $runner->finalize($probe, 'interactive', $actions);
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>|GatewayApiException
     */
    private function runGatewayDoctor(DoctorReportRunner $runner, string $mode, array $families): array|GatewayApiException
    {
        try {
            if ($mode === 'verify') {
                $dto = app(GatewayConnector::class)
                    ->send($this->gatewayRunRequest($families))
                    ->dto();

                /** @var DoctorRunResponse $dto */
                return $dto->doctor;
            }

            if ($mode !== 'interactive') {
                $dto = app(GatewayConnector::class)
                    ->send($this->gatewayFixRequest($mode, $families))
                    ->dto();

                /** @var DoctorRunResponse $dto */
                return $dto->doctor;
            }

            $probeDto = app(GatewayConnector::class)
                ->send($this->gatewayRunRequest($families))
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
                    ->send($this->gatewayFixRequest($resolutionMode, $families, $issues))
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
    private function gatewayRunRequest(array $families): RunDoctorRequest
    {
        return new RunDoctorRequest(
            families: $families,
            node: $this->stringOption('node'),
            self: (bool) $this->option('self'),
            app: $this->stringOption('app'),
            workspace: $this->stringOption('workspace'),
        );
    }

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>|null  $issues
     */
    private function gatewayFixRequest(string $mode, array $families, ?array $issues = null): FixDoctorRequest
    {
        return new FixDoctorRequest(
            mode: $mode,
            families: $families,
            issues: $issues,
            node: $this->stringOption('node'),
            self: (bool) $this->option('self'),
            app: $this->stringOption('app'),
            workspace: $this->stringOption('workspace'),
        );
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

        return "Resolve {$family} issue {$key} on {$node}?";
    }

    private function localNode(): ?Node
    {
        $node = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->first();

        return $node instanceof Node ? $node : null;
    }

    private function isGatewayCaller(): bool
    {
        return $this->callerRole() === 'gateway';
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function mode(): string
    {
        if (! (bool) $this->option('fix')) {
            return 'verify';
        }

        if ((bool) $this->option('restore')) {
            return 'restore';
        }

        if ((bool) $this->option('adopt')) {
            return 'adopt';
        }

        return 'interactive';
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
    private function renderDoctoringPanel(array $families): void
    {
        $width = 78;
        $innerWidth = $width - 2;
        $target = $this->doctorHumanValue($this->stringOption('node') ?? $this->localNode()?->name);
        $lines = [
            $this->doctorPanelRule('top', 'D O C T O R I N G', $width),
            $this->doctorPanelEmpty($innerWidth),
            $this->doctorPanelRule('middle', "Performing check-up on {$target}", $width),
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
        $width = 78;
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
            $lines[] = $this->doctorPanelBullet($this->doctorFamilyLabel($family), $this->doctorFamilyStatus($familyIssues, $familyActions), $innerWidth);

            if ($familyIssues !== []) {
                $lines = [
                    ...$lines,
                    ...$this->doctorPanelIssueTable($familyIssues, $innerWidth),
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

        $remaining = max(2, $width - mb_strlen($label) - 4);
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

    private function doctorPanelBullet(string $label, string $status, int $innerWidth): string
    {
        $left = '●  '.str_pad($label, 14);
        $text = $left.$status;

        return $this->doctorPanelText($text, $innerWidth);
    }

    private function doctorPanelText(string $text, int $innerWidth): string
    {
        $text = mb_strimwidth($text, 0, $innerWidth, '…');

        return $text.str_repeat(' ', max(0, $innerWidth - mb_strlen($text))).'│';
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<string>
     */
    private function doctorPanelIssueTable(array $issues, int $innerWidth): array
    {
        $lines = [$this->doctorPanelSeparator(['KEY', 'Issue'], [24, $innerWidth - 29])];

        foreach ($issues as $issue) {
            $lines[] = $this->doctorPanelCells([
                $this->doctorString($issue['key'] ?? null),
                $this->doctorString($issue['summary'] ?? null),
            ], [24, $innerWidth - 29]);
        }

        $lines[] = $this->doctorPanelSeparator([], []);

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<string>
     */
    private function doctorPanelActionTable(array $actions, int $innerWidth): array
    {
        $lines = [$this->doctorPanelSeparator(['ACTION', 'STATUS', 'KEY', 'Summary'], [10, 10, 22, $innerWidth - 50])];

        foreach ($actions as $action) {
            $lines[] = $this->doctorPanelCells([
                $this->doctorString($action['mode'] ?? null),
                $this->doctorString($action['status'] ?? null),
                $this->doctorString($action['key'] ?? $action['code'] ?? null),
                $this->doctorString($action['summary'] ?? null),
            ], [10, 10, 22, $innerWidth - 50]);
        }

        $lines[] = $this->doctorPanelSeparator([], []);

        return $lines;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<int>  $widths
     */
    private function doctorPanelSeparator(array $headers, array $widths): string
    {
        if ($headers === []) {
            return '├'.str_repeat('─', 76).'┤';
        }

        return $this->doctorPanelCells($headers, $widths);
    }

    /**
     * @param  list<string>  $values
     * @param  list<int>  $widths
     */
    private function doctorPanelCells(array $values, array $widths): string
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $cellWidth = $widths[$index] ?? 10;
            $value = mb_strimwidth($value, 0, $cellWidth, '…');
            $cells[] = ' '.str_pad($value, $cellWidth);
        }

        return '├'.implode('│', $cells).'┤';
    }

    /**
     * @param  array<string, mixed>  $doctor
     * @return list<string>
     */
    private function doctorFamiliesForPanel(array $doctor): array
    {
        $scope = is_array($doctor['scope'] ?? null) ? $doctor['scope'] : [];
        $families = is_array($scope['families'] ?? null) ? array_values(array_filter($scope['families'], is_string(...))) : [];

        return $families === [] ? ['node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule'] : $families;
    }

    private function doctorFamilyLabel(string $family): string
    {
        return match ($family) {
            'node' => 'Nodes',
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

        $issues = $this->doctorList($doctor, 'issues');
        $categoryCount = count(array_unique(array_map(
            fn (array $issue): string => $this->doctorString($issue['family'] ?? null),
            $issues,
        )));

        return $issueCount === 1
            ? '1 issue detected across 1 category'
            : "{$issueCount} issues detected across {$categoryCount} categories";
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
