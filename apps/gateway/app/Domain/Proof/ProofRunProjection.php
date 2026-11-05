<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use JsonException;

/**
 * Projects the terminal run of a proof. The verdicts come from the final line of `bin/proof-fleet verify`, which the
 * run keeps as the stdout of its case step; the Gateway holds no other source and never calls Swamp or the VM.
 */
final readonly class ProofRunProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** The step that runs every selected case and prints one JSON line with a verdict for each. */
    public const string Verify = 'Run the cases';

    /** The native assert: every selected case passed. */
    public const string EndCheck = 'Check the verdicts';

    private const array Verdicts = ['p' => 'passed', 'f' => 'failed', 'u' => 'unrun'];

    public function __construct(private ProofVmClaim $vms) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== OperationKinds::ProofRun || ! is_string($input['commit'] ?? null) || ! is_array($input['cases'] ?? null)
            || $input['cases'] === [] || ! is_array($input['vm'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A proof run needs its admitted commit, cases and VM.', 422);
        }

        return ['intent' => array_intersect_key($input, array_flip(['commit', 'cases', 'uncovered', 'vm', 'ssh_execution']))];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $report = $this->report(ValidatedData::object($data['result'] ?? []));
        $cases = $this->cases($operation, $report);
        $passed = $cases !== [] && array_all($cases, static fn (array $case): bool => $case['verdict'] === 'passed');
        if ($status === OperationStatus::Succeeded && ! $passed) {
            throw new ResourceOperationException('operation.result_invalid', 'A succeeded proof run needs a passed verdict for every case.', 422);
        }
        if ($status === OperationStatus::Failed) {
            $data['error_code'] = ($report['cases_ran'] ?? false) === true ? 'proof.cases_failed' : 'proof.run_failed';
        }
        $result = ['commit' => $operation->input['commit'], 'outcome' => $status === OperationStatus::Succeeded ? 'passed' : 'failed', 'cases' => $cases,
            'uncovered' => $operation->input['uncovered'] ?? [], 'vm' => ValidatedData::object($operation->input['vm'])['name'], 'receipt_url' => null];
        $projected = app(DirectOperation::class)->project($operation, $data, $result);
        if ($status->terminal()) {
            $this->vms->release(ValidatedData::string(ValidatedData::object($operation->input['vm'])['name']));
        }

        return $projected;
    }

    /**
     * The one JSON line of the case step, or null when the run never printed it.
     *
     * @param  array<string,mixed>  $native
     * @return array{summary: list<array{0: string, 1: int|float}>, cases_ran: bool}|null
     */
    private function report(array $native): ?array
    {
        $steps = is_array($native['steps'] ?? null) ? $native['steps'] : [];
        $step = is_array($steps[self::Verify] ?? null) ? $steps[self::Verify] : [];
        try {
            $line = json_decode(ValidatedData::string($step['stdout'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $summary = is_array($line) ? ($line['summary'] ?? null) : null;
        if (! is_array($summary) || ! array_is_list($summary) || array_any($summary, static fn (mixed $row): bool => ! is_array($row) || count($row) !== 2
            || ! is_string($row[0] ?? null) || ! isset(self::Verdicts[$row[0]]) || ! (is_int($row[1]) || is_float($row[1])))) {
            return null;
        }

        return ['summary' => $summary, 'cases_ran' => ($line['cases_ran'] ?? false) === true];
    }

    /**
     * Each admitted case with its verdict. A run with no usable report leaves every case unrun.
     *
     * @param  array{summary: list<array{0: string, 1: int|float}>, cases_ran: bool}|null  $report
     * @return list<array{program: string, command: string, verdict: string, seconds: int|float}>
     */
    private function cases(Operation $operation, ?array $report): array
    {
        $admitted = array_values(is_array($operation->input['cases']) ? $operation->input['cases'] : []);
        $summary = $report !== null && count($report['summary']) === count($admitted) ? $report['summary'] : null;
        $cases = [];
        foreach ($admitted as $index => $case) {
            $case = ValidatedData::object($case);
            $row = $summary[$index] ?? ['u', 0];
            $cases[] = ['program' => ValidatedData::string($case['program']), 'command' => ValidatedData::string($case['command']),
                'verdict' => self::Verdicts[$row[0]], 'seconds' => $row[1]];
        }

        return $cases;
    }
}
