<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Actions\AppDev\AdmitPrivateDnsPublicationAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/**
 * For Gateway code that still needs private DNS live before it goes on: admits one `dns.publish` operation per vpn
 * Node with a fresh render and waits for its end. A publication that finds the Node busy waits for the running one
 * and renders again, because the newest render holds every change stored before it.
 */
final readonly class SwampPrivateDnsManager implements PrivateDnsManager
{
    private const int Attempts = 3;

    public function __construct(
        private AdmitPrivateDnsPublicationAction $admit,
        private int $timeoutSeconds = 180,
    ) {}

    /** Publishes to every vpn Node, then throws the first failure: one Node that fails must not leave the others stale. */
    public function converge(): void
    {
        $failure = null;

        foreach (PrivateDnsPublicationNodes::query()->get() as $node) {
            try {
                $this->publish($node);
            } catch (RuntimeConvergenceException $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure instanceof RuntimeConvergenceException) {
            throw $failure;
        }
    }

    private function publish(Node $node): void
    {
        for ($attempt = 1; $attempt <= self::Attempts; $attempt++) {
            try {
                $operation = $this->admit->execute($node);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'operation.target_busy') {
                    throw $exception;
                }

                $busy = Operation::query()->where('lock_key', AdmitPrivateDnsPublicationAction::lockKey($node))->first();

                // The publication that holds the Node may fail; this caller renders again anyway, and its own result decides.
                if ($busy instanceof Operation) {
                    $this->await($busy, $node, mustSucceed: false);
                }

                continue;
            }

            $this->await($operation, $node);

            return;
        }

        throw $this->failed($node, 'The vpn Node stayed busy with other private DNS publications.');
    }

    private function await(Operation $operation, Node $node, bool $mustSucceed = true): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $operation->refresh();

            if ($operation->status->terminal()) {
                break;
            }

            if (microtime(true) >= $deadline) {
                throw $this->failed($node, "Private DNS publication on node [{$node->name}] did not finish in {$this->timeoutSeconds} seconds.");
            }

            usleep(500_000);
        }

        if ($mustSucceed && $operation->status !== OperationStatus::Succeeded) {
            throw $this->failed($node, "Could not converge Orbit private DNS records on node [{$node->name}].");
        }
    }

    private function failed(Node $node, string $message): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException(step: 'private-dns', errorCode: 'app-dev.dns_config_failed', message: $message);
    }
}
