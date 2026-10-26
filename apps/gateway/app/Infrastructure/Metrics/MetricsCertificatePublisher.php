<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Throwable;

/**
 * The Metrics site's certificate on the Gateway's Node, through the agent's `metrics.certificate`
 * (docs/reference/metrics.md). A publish answers a receipt that a later rollback restores.
 */
final readonly class MetricsCertificatePublisher
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function publish(Node $gateway, GatewayCertificatePaths $certificate): MetricsPublicationReceipt
    {
        $certificatePem = @file_get_contents($certificate->certificatePath);
        $privateKeyPem = @file_get_contents($certificate->privateKeyPath);

        try {
            if (! is_string($certificatePem) || ! is_string($privateKeyPem)) {
                throw new ResourceOperationException('metrics.certificate_publication_failed', 'Metrics certificate publication did not complete.', 502);
            }
            $result = $this->agents->run($gateway, 'metrics.certificate', [
                'operation' => 'publish',
                'version' => bin2hex(random_bytes(8)),
                'certificate' => $certificatePem,
                'key' => $privateKeyPem,
            ]);
        } catch (Throwable $exception) {
            throw new ResourceOperationException('metrics.certificate_publication_failed', 'Metrics certificate publication did not complete.', 502, previous: $exception);
        }

        return match ($result['change'] ?? null) {
            'unchanged' => MetricsPublicationReceipt::unchanged(),
            'created' => MetricsPublicationReceipt::created(),
            'replaced' => is_string($result['previous'] ?? null) && $result['previous'] !== ''
                ? MetricsPublicationReceipt::replaced($result['previous'])
                : throw new ResourceOperationException('metrics.certificate_publication_failed', 'Metrics certificate publication did not complete.', 502),
            default => throw new ResourceOperationException('metrics.certificate_publication_failed', 'Metrics certificate publication did not complete.', 502),
        };
    }

    public function restore(Node $gateway, MetricsPublicationReceipt $receipt): void
    {
        if ($receipt->isUnchanged()) {
            return;
        }

        try {
            $this->agents->run($gateway, 'metrics.certificate', [
                'operation' => 'restore',
                'change' => $receipt->wasCreated() ? 'created' : 'replaced',
                'previous' => $receipt->wasCreated() ? null : $receipt->previousPublication(),
            ]);
        } catch (Throwable $exception) {
            throw new ResourceOperationException('metrics.certificate_restoration_failed', 'Metrics certificate restoration did not complete.', 502, previous: $exception);
        }
    }

    public function remove(Node $gateway): void
    {
        try {
            $this->agents->run($gateway, 'metrics.certificate', ['operation' => 'remove']);
        } catch (Throwable $exception) {
            throw new ResourceOperationException('metrics.certificate_removal_failed', 'Metrics certificate removal did not complete.', 502, previous: $exception);
        }
    }
}
