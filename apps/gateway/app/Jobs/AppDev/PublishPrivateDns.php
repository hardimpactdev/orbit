<?php

declare(strict_types=1);

namespace App\Jobs\AppDev;

use App\Actions\AppDev\AdmitPrivateDnsPublicationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Publishes a fresh render of the private DNS after a failed run has been projected into the rows.
 *
 * A kind that restores the DNS in its own run publishes the restore with the revision it took at admission. A publication admitted
 * after that (another Route, a Node change) holds a newer revision, and the Node then skips the restore as superseded. That
 * publication rendered the rows while the failed change still stood. Rendering again after the failure is stored always makes
 * the newest revision the correct one, so no failed change stays in DNS. The render is the same when the restore already won, and the
 * Node then changes only its revision file. Dispatch it after the transaction that projects the failure commits.
 *
 * The job only admits. The operations queue has one worker, and it also settles the publication, so waiting for the end here would block it.
 * A Node that is busy with an earlier publication may have rendered before the failure was stored, so the job comes back for that Node.
 */
final class PublishPrivateDns implements ShouldQueue
{
    use Queueable;

    /** The wait between two looks at a busy Node, and how many looks the job makes (about five minutes). */
    public const int RetrySeconds = 15;

    public const int MaximumAttempts = 20;

    public int $tries = 3;

    public int $backoff = 15;

    /** @param list<int> $nodeIds The vpn Nodes still to publish to; empty means every vpn Node. */
    public function __construct(public array $nodeIds = [], public int $attempt = 1)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(AdmitPrivateDnsPublicationAction $admit): void
    {
        $query = PrivateDnsPublicationNodes::query();
        $busy = [];

        foreach ($this->nodeIds === [] ? $query->get() : $query->whereKey($this->nodeIds)->get() as $node) {
            try {
                $admit->execute($node);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'operation.target_busy') {
                    throw $exception;
                }

                $busy[] = $node->id;
            }
        }

        if ($busy === []) {
            return;
        }

        if ($this->attempt >= self::MaximumAttempts) {
            Log::warning('Private DNS was not republished after a failed change: the vpn Node stayed busy.', ['node_ids' => $busy]);

            return;
        }

        self::dispatch($busy, $this->attempt + 1)->delay(self::RetrySeconds);
    }
}
