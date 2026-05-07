<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
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

use function Laravel\Prompts\table;

#[Signature('doctor
    {--app= : Limit to one app}
    {--workspace= : Limit to one workspace}
    {--node= : Target node name}
    {--self : Limit to the calling node identity}
    {--family=* : Scope to one or more state families}
    {--fix : Reconcile drift toward gateway intent}
    {--adopt : Reconcile drift toward node reality}
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

        if ((bool) $this->option('fix') && (bool) $this->option('adopt')) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--fix and --adopt are mutually exclusive.',
                meta: ['fields' => ['fix', 'adopt']],
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
                message: 'App-node callers may not run doctor --fix or doctor --adopt for this scope.',
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

        $result = $this->isGatewayCaller()
            ? $this->runLocalDoctor($runner, $mode, $families)
            : $this->runGatewayDoctor($mode, $families);

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
        if (in_array($this->activityMode, ['fix', 'adopt'], true)) {
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

        return $runner->run($node, mode: $mode, families: $families);
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>|GatewayApiException
     */
    private function runGatewayDoctor(string $mode, array $families): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new RunDoctorRequest(
                    mode: $mode,
                    families: $families,
                    node: $this->stringOption('node'),
                    self: (bool) $this->option('self'),
                    app: $this->stringOption('app'),
                    workspace: $this->stringOption('workspace'),
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to run doctor.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var DoctorRunResponse $dto */
        return $dto->doctor;
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
        if ((bool) $this->option('adopt')) {
            return 'adopt';
        }

        return (bool) $this->option('fix') ? 'fix' : 'verify';
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
        $this->line($this->doctorHeading($doctor));
        $this->line('Mode: '.$this->doctorModeLabel($doctor));
        $this->line('Scope: '.$this->doctorScopeLabel($doctor));
        $this->newLine();

        $summaryWidth = $this->renderDoctorSummary($doctor);
        $resultWidth = max($summaryWidth, $this->doctorResultBodyWidth($doctor), 52);

        $this->newLine();
        $this->line($this->centeredDoctorDivider($resultWidth));
        $this->newLine();

        $issues = $this->doctorList($doctor, 'issues');
        $actions = $this->doctorList($doctor, 'actions');

        if ($issues === [] && $actions === []) {
            $this->renderDoctorSuccessBox($resultWidth);

            return;
        }

        if ($issues !== []) {
            $this->renderDoctorIssues($issues, $doctor);
        }

        if ($actions !== []) {
            if ($issues !== []) {
                $this->newLine();
            }

            $this->renderDoctorActions($actions);
        }
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    private function doctorHeading(array $doctor): string
    {
        $healthy = ($doctor['healthy'] ?? false) === true;

        return match ($this->doctorModeLabel($doctor)) {
            'fix' => $healthy ? 'Doctor repaired drift.' : 'Doctor could not repair all drift.',
            'adopt' => $healthy ? 'Doctor adopted compatible reality.' : 'Doctor could not adopt all drift.',
            default => $healthy ? 'Doctor healthy.' : 'Doctor found drift.',
        };
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    private function doctorModeLabel(array $doctor): string
    {
        $mode = $doctor['mode'] ?? 'verify';

        return is_string($mode) && in_array($mode, ['verify', 'fix', 'adopt'], true)
            ? $mode
            : 'verify';
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    private function doctorScopeLabel(array $doctor): string
    {
        $scope = is_array($doctor['scope'] ?? null) ? $doctor['scope'] : [];
        $families = $scope['families'] ?? [];
        $familyLabel = is_array($families) && $families !== []
            ? implode(',', array_values(array_filter($families, static fn (mixed $family): bool => is_string($family) && $family !== '')))
            : 'all';

        return sprintf(
            'families=%s node=%s app=%s workspace=%s self=%s',
            $familyLabel,
            $this->doctorHumanValue($scope['node'] ?? null),
            $this->doctorHumanValue($scope['app'] ?? null),
            $this->doctorHumanValue($scope['workspace'] ?? null),
            ($scope['self'] ?? false) === true ? 'true' : 'false',
        );
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    private function renderDoctorSummary(array $doctor): int
    {
        $summary = is_array($doctor['summary'] ?? null) ? $doctor['summary'] : [];
        $headers = ['ISSUES', 'FIXED', 'ADOPTED', 'SKIPPED', 'CONFLICTS', 'FAILED'];
        $rows = [[
            (string) (int) ($summary['issues'] ?? 0),
            (string) (int) ($summary['fixed'] ?? 0),
            (string) (int) ($summary['adopted'] ?? 0),
            (string) (int) ($summary['skipped'] ?? 0),
            (string) (int) ($summary['conflicts'] ?? 0),
            (string) (int) ($summary['failed'] ?? 0),
        ]];

        $this->line('Summary');
        table(headers: $headers, rows: $rows);

        return $this->doctorTableWidth($headers, $rows);
    }

    private function centeredDoctorDivider(int $width): string
    {
        $label = 'D O C T O R   R E S U L T';
        $remaining = max(2, $width - mb_strlen($label) - 2);
        $left = intdiv($remaining, 2);
        $right = $remaining - $left;

        return str_repeat('─', $left).' '.$label.' '.str_repeat('─', $right);
    }

    private function renderDoctorSuccessBox(int $width): void
    {
        $message = 'Everything is healthy!';
        $innerWidth = max(mb_strlen($message) + 10, $width - 2);
        $padding = $innerWidth - mb_strlen($message);
        $left = intdiv($padding, 2);
        $right = $padding - $left;

        $this->line('┌'.str_repeat('─', $innerWidth).'┐');
        $this->line('│'.str_repeat(' ', $innerWidth).'│');
        $this->line('│'.str_repeat(' ', $left).$message.str_repeat(' ', $right).'│');
        $this->line('│'.str_repeat(' ', $innerWidth).'│');
        $this->line('└'.str_repeat('─', $innerWidth).'┘');
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

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function renderDoctorActions(array $actions): void
    {
        $headers = ['FAMILY', 'NODE', 'MODE', 'STATUS', 'KEY', 'SUMMARY'];
        $rows = [];

        foreach ($actions as $action) {
            $rows[] = [
                $this->doctorString($action['family'] ?? null),
                $this->doctorHumanValue($action['node'] ?? null),
                $this->doctorString($action['mode'] ?? null),
                $this->doctorString($action['status'] ?? null),
                $this->doctorString($action['key'] ?? $action['code'] ?? null),
                $this->doctorString($action['summary'] ?? null),
            ];
        }

        $this->line('Actions');
        table(headers: $headers, rows: $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, mixed>  $doctor
     */
    private function renderDoctorIssues(array $issues, array $doctor): void
    {
        $issueLabel = count($issues) === 1 ? '1 issue found' : count($issues).' issues found';

        $this->line($issueLabel);

        foreach ($this->groupDoctorIssues($issues, $doctor) as $family => $kinds) {
            foreach ($kinds as $kind => $kindIssues) {
                $headers = ['NODE', 'KEY', 'SUMMARY', 'NEXT'];
                $rows = [];

                foreach ($kindIssues as $issue) {
                    $rows[] = [
                        $this->doctorHumanValue($issue['node'] ?? null),
                        $this->doctorString($issue['key'] ?? $issue['code'] ?? null),
                        $this->doctorString($issue['summary'] ?? null),
                        $this->doctorHumanValue($this->doctorIssueNextStep($issue)),
                    ];
                }

                $this->line((string) $family.' / '.(string) $kind);
                table(headers: $headers, rows: $rows);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, mixed>  $doctor
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function groupDoctorIssues(array $issues, array $doctor): array
    {
        usort($issues, function (array $left, array $right) use ($doctor): int {
            $familyCompare = $this->doctorFamilySortIndex($left, $doctor) <=> $this->doctorFamilySortIndex($right, $doctor);

            if ($familyCompare !== 0) {
                return $familyCompare;
            }

            return $this->doctorKindSortIndex($left) <=> $this->doctorKindSortIndex($right);
        });

        $groups = [];

        foreach ($issues as $issue) {
            $family = $this->doctorString($issue['family'] ?? null);
            $kind = $this->doctorString($issue['kind'] ?? null);
            $groups[$family][$kind][] = $issue;
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array<string, mixed>  $doctor
     */
    private function doctorFamilySortIndex(array $issue, array $doctor): int
    {
        $family = $this->doctorString($issue['family'] ?? null);
        $scope = is_array($doctor['scope'] ?? null) ? $doctor['scope'] : [];
        $families = is_array($scope['families'] ?? null) ? array_values($scope['families']) : [];
        $index = array_search($family, $families, true);

        if ($index !== false) {
            return (int) $index;
        }

        $fallback = ['node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule'];
        $fallbackIndex = array_search($family, $fallback, true);

        return $fallbackIndex === false ? 100 : (int) $fallbackIndex;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function doctorKindSortIndex(array $issue): int
    {
        $order = ['unverifiable', 'missing', 'divergent', 'extra'];
        $index = array_search($this->doctorString($issue['kind'] ?? null), $order, true);

        return $index === false ? 100 : (int) $index;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function doctorIssueNextStep(array $issue): ?string
    {
        $detail = $issue['detail'] ?? $issue['details'] ?? null;

        if (! is_array($detail)) {
            return null;
        }

        $next = $detail['next'] ?? $detail['next_step'] ?? null;

        return is_string($next) && $next !== '' ? $next : null;
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    private function doctorResultBodyWidth(array $doctor): int
    {
        $width = 0;

        $actionRows = [];

        foreach ($this->doctorList($doctor, 'actions') as $action) {
            $actionRows[] = [
                $this->doctorString($action['family'] ?? null),
                $this->doctorString($action['node'] ?? null),
                $this->doctorString($action['mode'] ?? null),
                $this->doctorString($action['status'] ?? null),
                $this->doctorString($action['key'] ?? $action['code'] ?? null),
                $this->doctorString($action['summary'] ?? null),
            ];
        }

        if ($actionRows !== []) {
            $width = max($width, $this->doctorTableWidth(['FAMILY', 'NODE', 'MODE', 'STATUS', 'KEY', 'SUMMARY'], $actionRows));
        }

        foreach ($this->groupDoctorIssues($this->doctorList($doctor, 'issues'), $doctor) as $kinds) {
            foreach ($kinds as $kindIssues) {
                $issueRows = [];

                foreach ($kindIssues as $issue) {
                    $issueRows[] = [
                        $this->doctorHumanValue($issue['node'] ?? null),
                        $this->doctorString($issue['key'] ?? $issue['code'] ?? null),
                        $this->doctorString($issue['summary'] ?? null),
                        $this->doctorHumanValue($this->doctorIssueNextStep($issue)),
                    ];
                }

                $width = max($width, $this->doctorTableWidth(['NODE', 'KEY', 'SUMMARY', 'NEXT'], $issueRows));
            }
        }

        return $width;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function doctorTableWidth(array $headers, array $rows): int
    {
        $widths = array_map(mb_strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($value));
            }
        }

        return array_sum($widths) + count($widths) * 3 + 1;
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
