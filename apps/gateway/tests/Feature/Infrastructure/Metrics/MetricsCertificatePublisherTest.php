<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsCertificatePublisher;
use App\Infrastructure\Metrics\MetricsPublicationReceipt;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\TestOrbitHome;

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

function metrics_publication_certificate(): GatewayCertificatePaths
{
    $directory = TestOrbitHome::scratch('metrics-publication');
    mkdir($directory, 0o700, true);
    file_put_contents("{$directory}/metrics.pem", "CERTIFICATE\n");
    file_put_contents("{$directory}/metrics.key", "KEY\n");

    return new GatewayCertificatePaths(privateKeyPath: "{$directory}/metrics.key", certificatePath: "{$directory}/metrics.pem");
}

it('publishes the certificate content and answers each receipt', function (array $answer, MetricsPublicationReceipt $receipt): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.certificate', $answer);

    expect(new MetricsCertificatePublisher($agents)->publish(new Node(['name' => 'gateway']), metrics_publication_certificate()))->toEqual($receipt)
        ->and($agents->calls[0]['input'])->toMatchArray(['operation' => 'publish', 'certificate' => "CERTIFICATE\n", 'key' => "KEY\n"]);
})->with([
    'created' => [['change' => 'created', 'previous' => null], MetricsPublicationReceipt::created()],
    'unchanged' => [['change' => 'unchanged', 'previous' => null], MetricsPublicationReceipt::unchanged()],
    'replaced' => [['change' => 'replaced', 'previous' => '/etc/caddy/orbit-metrics-cert-versions/a'], MetricsPublicationReceipt::replaced('/etc/caddy/orbit-metrics-cert-versions/a')],
]);

it('restores a replaced or created publication and skips an unchanged one', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.certificate', []);
    $publisher = new MetricsCertificatePublisher($agents);
    $gateway = new Node(['name' => 'gateway']);

    $publisher->restore($gateway, MetricsPublicationReceipt::replaced('/etc/caddy/orbit-metrics-cert-versions/a'));
    $publisher->restore($gateway, MetricsPublicationReceipt::created());
    $publisher->restore($gateway, MetricsPublicationReceipt::unchanged());

    expect(array_column($agents->calls, 'input'))->toBe([
        ['operation' => 'restore', 'change' => 'replaced', 'previous' => '/etc/caddy/orbit-metrics-cert-versions/a'],
        ['operation' => 'restore', 'change' => 'created', 'previous' => null],
    ]);
});

it('reports a failed publication with its public error', function (): void {
    $agents = new FakeAgentCommandRunner()->refuse('metrics.certificate', 'metrics.certificate_failed', ['reason' => 'reload failed']);

    expect(fn () => new MetricsCertificatePublisher($agents)->publish(new Node(['name' => 'gateway']), metrics_publication_certificate()))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.certificate_publication_failed'));
});
