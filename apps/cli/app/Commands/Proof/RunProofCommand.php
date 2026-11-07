<?php

declare(strict_types=1);

namespace App\Commands\Proof;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use App\Support\Console\PromptAborted;
use App\Support\GatedExtensionCommand;
use Illuminate\Support\Str;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Proof\RunProofRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Starts a `proof.run` operation and follows it until it ends. Ctrl-C and SIGTERM cancel the run. */
class RunProofCommand extends GatewayCommand implements GatedExtensionCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'proof:run
        {commit? : Full 40 or 64 character lowercase commit SHA}
        {--all : Run every case, not only the cases that the commit selects}
        {--report-only : Yield capacity to task and proof work}
        {--keep-failed : Keep the VM of a run that failed for 60 minutes, to read it}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Prove the commands that a commit changes in a disposable test fleet and report a verdict for each case. Ctrl-C cancels the run.';

    public function extensionSlug(): ?string
    {
        return 'tasks';
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $commit = $this->argument('commit');

        if ($commit === null && $this->consoleMode()->mayPrompt) {
            try {
                $commit = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                    'Commit',
                    placeholder: '0123456789abcdef0123456789abcdef01234567',
                    required: true,
                    validate: self::commitError(...),
                ));
            } catch (PromptAborted|ConsoleInterrupted) {
                return $this->renderGatewayFailure('input.cancelled', 'No commit was selected.');
            }
        }

        if (! is_string($commit) || self::commitError($commit) !== null) {
            return $this->renderGatewayFailure(
                'proof.commit_invalid',
                'Name the commit by its full 40 or 64 character lowercase SHA.',
                details: ['field' => 'commit'],
            );
        }

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $connector = $connectors->make($profile);
        $key = $this->option('idempotency-key');
        $key = is_string($key) && $key !== '' ? $key : 'proof.run.'.Str::uuid();
        $operation = $this->send($connector, new RunProofRequest($commit, $this->option('all') === true, $this->option('report-only') === true, $key, $this->option('keep-failed') === true), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Prove commit '.substr($commit, 0, 12), 'Proof')
            : self::FAILURE;
    }

    protected function interruptCancelsOperation(): bool
    {
        return true;
    }

    protected function operationOutcome(OperationResponse $operation, string $verb): ?ProgressOutcome
    {
        if (! $operation->succeeded()) {
            return null;
        }

        return match ($operation->result['outcome'] ?? null) {
            'nothing_to_prove' => new ProgressOutcome(ProgressState::Skipped, 'Skipped — nothing to prove (proof.nothing_to_prove)'),
            'passed' => new ProgressOutcome(ProgressState::Success, 'Proof passed.'),
            default => null,
        };
    }

    protected function writeOperationResult(OperationResponse $operation): void
    {
        $result = $operation->result ?? [];
        $rows = [];

        foreach (self::entries($result['cases'] ?? null) as $case) {
            $name = self::text($case['case'] ?? null);
            $file = self::text($case['file'] ?? null);
            $seconds = $case['seconds'] ?? null;
            $rows[] = [
                self::text($case['command'] ?? null),
                $name !== '' ? $name : ($file === '' ? '' : basename($file)),
                self::text($case['verdict'] ?? null),
                is_int($seconds) || is_float($seconds) ? number_format((float) $seconds, 1).' s' : '',
            ];
        }

        if ($rows !== []) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(['Command', 'Case', 'Verdict', 'Duration'], $rows));
        }

        // A proof that failed at admission has no case: the reason is on the Operation (a refusal used to come back from the request).
        $detail = $operation->error['detail'] ?? null;

        if (! $operation->succeeded() && is_string($detail) && $detail !== '') {
            $this->writeHumanMessage('Reason: '.$detail);
        }

        foreach (self::entries($result['uncovered'] ?? null) as $path) {
            $this->writeHumanMessage('Uncovered: '.self::text($path['path'] ?? null).' ('.self::text($path['reason'] ?? null).')');
        }

        // `--keep-failed`: the VM of a run that failed stays until the time shown, and then the Gateway destroys it.
        foreach (self::entries($result['kept'] ?? null) as $kept) {
            $group = $kept['group_id'] ?? null;
            $released = self::text($kept['released_at'] ?? null);
            $by = $kept['released_by'] ?? null;
            $this->writeHumanMessage('Kept VM: '.self::text($kept['vm'] ?? null).' of '.self::text($kept['command'] ?? null).', group '.(is_int($group) ? $group : '?').', '
                .($released !== '' ? 'released at '.$released.(is_int($by) ? ' (operation '.$by.')' : '') : 'destroyed after '.self::text($kept['keep_until'] ?? null)));
        }

        // A run whose VM workspace was not at the commit under proof ran no case: both commits say what is wrong.
        foreach (self::entries($result['workspace'] ?? null) as $workspace) {
            $found = self::text($workspace['found'] ?? null);
            $this->writeHumanMessage('Workspace of '.self::text($workspace['command'] ?? null).': expected '.self::text($workspace['expected'] ?? null).', found '.($found === '' ? 'no commit' : $found));
        }

        foreach (['vm' => 'VM', 'receipt_url' => 'Receipt'] as $field => $label) {
            $value = $result[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $this->writeHumanMessage("{$label}: {$value}");
            }
        }
    }

    private static function commitError(string $commit): ?string
    {
        return preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/D', $commit) === 1 ? null : 'Enter the full 40 or 64 character lowercase SHA.';
    }

    /** @return list<array<array-key, mixed>> */
    private static function entries(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
