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
        $issues = (int) ($doctor['summary']['issues'] ?? 0);
        $this->line($issues === 0 ? 'Doctor: healthy.' : "Doctor: {$issues} issue(s) found.");
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
