<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\State\SecretRedactor;
use App\E2E\TopologyAcquirer;
use InvalidArgumentException;
use Throwable;

final class ExecCommand extends E2ECommand
{
    use ReadsGuestArgv;
    use RecordsEvidence;

    #[\Override]
    protected $signature =
        'topology:exec {issue} {node} '
        .self::WORKTREE_OPTION
        .' {--argv=} {--argv-file=}'
        .' {--timeout=60 : Seconds the guest command may run, from 1 to 3600}'
        .self::RECORD_OPTION
        .' {--json}';

    #[\Override]
    protected $description = 'Execute exact argv as orbit on one Node of the discovery topology';

    public function handle(TopologyAcquirer $acquirer, SecretRedactor $redactor): int
    {
        try {
            [$argv, $stdin] = $this->commandInput();
            $timeout = $this->timeoutOption();
            $label = $this->evidenceLabel();
            $request = $this->request();
            $role = (string) $this->argument('node');
            $startedAt = $this->evidenceClock();
            $result = $acquirer->execute($request, $role, $argv, $stdin, $timeout);
            $this->recordEvidence($label, $request, $role, $argv, $result, $startedAt, $this->evidenceClock());
            $redactedArgv = json_encode($redactor->redactArgv($argv), JSON_THROW_ON_ERROR);
            $this->log($request, "node={$role} exit={$result->exitCode} argv={$redactedArgv}");
            $payload = [
                'state' => 'executed',
                'exit_code' => $result->exitCode,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
            ];
            $this->line(
                $this->option('json')
                    ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : $result->stdout,
            );

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    private function timeoutOption(): int
    {
        $value = $this->option('timeout');
        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,3}\z/', $value) !== 1 || (int) $value > 3600) {
            throw new InvalidArgumentException('--timeout must be a whole number of seconds from 1 to 3600.');
        }

        return (int) $value;
    }
}
