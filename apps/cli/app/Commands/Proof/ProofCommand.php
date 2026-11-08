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
use Orbit\Sdk\Requests\Proof\VerifyProofRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Starts a `proof.verify` operation, which runs the cases of one command on the task VM that calls, and follows it until it ends. Ctrl-C and SIGTERM cancel the run. */
class ProofCommand extends GatewayCommand implements GatedExtensionCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'proof
        {orbit-command? : The Orbit command whose cases to run, for example firewall:allow}
        {--group= : Prove on the VM of this task group; for a Node with Gateway access}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Run the cases of one Orbit command on this task VM, or on the VM of --group, and report a verdict for each. Ctrl-C cancels the run.';

    public function extensionSlug(): ?string
    {
        return 'tasks';
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $command = $this->argument('orbit-command');

        if ($command === null && $this->consoleMode()->mayPrompt) {
            try {
                $command = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                    'Command',
                    placeholder: 'firewall:allow',
                    required: true,
                    validate: self::commandError(...),
                ));
            } catch (PromptAborted|ConsoleInterrupted) {
                return $this->renderGatewayFailure('input.cancelled', 'No command was selected.');
            }
        }

        if (! is_string($command) || self::commandError($command) !== null) {
            return $this->renderGatewayFailure(
                'proof.command_invalid',
                'Name the command as family:action, for example firewall:allow.',
                details: ['field' => 'command'],
            );
        }

        $group = $this->option('group');
        if ($group !== null && (! ctype_digit($group) || (int) $group < 1)) {
            return $this->renderGatewayFailure('proof.group_invalid', 'Name the task group by its numeric ID.', details: ['field' => 'group']);
        }

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $connector = $connectors->make($profile);
        $key = $this->option('idempotency-key');
        $key = is_string($key) && $key !== '' ? $key : 'proof.verify.'.Str::uuid();
        $operation = $this->send($connector, new VerifyProofRequest($command, $key, $group === null ? null : (int) $group), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Prove '.$command, 'Proof')
            : self::FAILURE;
    }

    protected function interruptCancelsOperation(): bool
    {
        return true;
    }

    protected function operationOutcome(OperationResponse $operation, string $verb): ?ProgressOutcome
    {
        if (! $operation->succeeded() || ($operation->result['outcome'] ?? null) !== 'passed') {
            return null;
        }

        return new ProgressOutcome(ProgressState::Success, 'Proof passed.');
    }

    protected function writeOperationResult(OperationResponse $operation): void
    {
        $rows = [];

        foreach (self::entries($operation->result['cases'] ?? null) as $case) {
            $name = self::text($case['case'] ?? null);
            $program = self::text($case['program'] ?? null);
            $seconds = $case['seconds'] ?? null;
            $rows[] = [
                self::text($case['command'] ?? null),
                $name !== '' ? $name : basename($program),
                self::text($case['verdict'] ?? null),
                is_int($seconds) || is_float($seconds) ? number_format((float) $seconds, 1).' s' : '',
            ];
        }

        if ($rows !== []) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(['Command', 'Case', 'Verdict', 'Duration'], $rows));
        }

        foreach (self::entries($operation->result['cases'] ?? null) as $case) {
            if (($case['verdict'] ?? null) === 'passed') {
                continue;
            }
            $failed = array_filter(array_map(self::text(...), is_array($case['failed'] ?? null) ? $case['failed'] : []));
            $detail = trim(self::text($case['cause'] ?? null).($failed === [] ? '' : ': '.implode('; ', $failed)));
            $this->writeHumanMessage('Not passed: '.(self::text($case['case'] ?? null) ?: basename(self::text($case['program'] ?? null))).($detail === '' ? '' : ' ('.$detail.')'));
        }
    }

    private static function commandError(string $command): ?string
    {
        return preg_match('/\A[a-z][a-z0-9_]*(?::[a-z][a-z0-9_-]*)+\z/D', $command) === 1 ? null : 'Enter the command as family:action, for example firewall:allow.';
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
