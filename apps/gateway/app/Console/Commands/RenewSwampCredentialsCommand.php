<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SwampCredentials\RenewSwampCredentialsAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SwampCredentials\SwampGatewayToken;
use App\Infrastructure\SwampCredentials\SwampNodeTokens;
use App\Infrastructure\SwampCredentials\SwampNodeUnconvergedException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class RenewSwampCredentialsCommand extends Command
{
    #[\Override]
    protected $signature = 'gateway:swamp-credentials {--release : A release candidate: also renew the Swamp Node tokens, cache this release and require live tokens}
        {--revoke : Only revoke Gateway token predecessors that no process can hold, as after a release handover}
        {--mint-for-host= : read or run: in a release container, print a due token\'s verified reveal for the host installer}';

    #[\Override]
    protected $description = 'Renew the Gateway Swamp read and run tokens within ten days of expiry; revoke predecessors no process holds.';

    public function handle(RenewSwampCredentialsAction $renew, SwampNodeTokens $node): int
    {
        try {
            $host = $this->option('mint-for-host');
            if (is_string($host)) {
                // Only the reveal goes to stdout, into the host installer's pipe; nothing when the token is not due.
                $reveal = $renew->mintForHost(SwampGatewayToken::from($host));
                if ($reveal !== null) {
                    $this->output->writeln($reveal, OutputInterface::OUTPUT_RAW);
                }

                return self::SUCCESS;
            }
            $actions = $this->option('revoke') === true
                ? $renew->revokePredecessors($node->list())
                : $renew->execute($this->option('release') === true);
            $this->info($actions === [] ? 'No Gateway Swamp token change is due.' : ucfirst(implode('; ', $actions)).'.');

            return self::SUCCESS;
        } catch (SwampNodeUnconvergedException $exception) {
            // The first release after this change can run before the Swamp Node's swamp role converge: a release, its revoke and its
            // host mint skip with one clear line. The hourly run still fails, so an unconverged Node stays visible.
            if ($this->option('release') !== true && $this->option('revoke') !== true && ! is_string($this->option('mint-for-host'))) {
                return $this->failed($exception);
            }
            $this->stderr('gateway.swamp_credentials_skipped: '.$exception->getMessage().' Token renewal and the release token gate were skipped.');

            return self::SUCCESS;
        } catch (GatewayReleaseException $exception) {
            // A release holds the lock and renews itself; the next hourly run reads the env again, ten days before expiry.
            if ($exception->errorCode === 'gateway.release_in_progress' && $this->option('release') !== true) {
                $this->info('A Gateway release holds the release lock; the Swamp tokens are checked at the next hourly run.');

                return self::SUCCESS;
            }

            return $this->failed($exception);
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }
    }

    private function stderr(string $message): void
    {
        $output = $this->output->getOutput();
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);
        } else {
            fwrite(STDERR, $message.PHP_EOL);
        }
    }

    /** Names the failure by its error code and message; neither carries a token, and --mint-for-host sends errors to stderr only. */
    private function failed(Throwable $exception): int
    {
        $code = $exception instanceof ResourceOperationException ? $exception->errorCode : 'gateway.swamp_credentials_failed';
        $message = $code.': '.$exception->getMessage();
        if (is_string($this->option('mint-for-host'))) {
            $this->stderr($message);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
