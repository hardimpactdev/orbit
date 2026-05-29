<?php

declare(strict_types=1);

namespace App\Commands\S3;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use Orbit\Core\Progress\ProgressEventType;

use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

final class S3PublishCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 's3:publish
        {host? : Public DNS hostname for S3 (e.g. s3.example.com)}
        {--node= : Active s3 node to publish on}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Publish a public HTTPS hostname for the fleet S3 service.';

    public function handle(): int
    {
        $host = $this->resolveHost();

        if ($host === null) {
            return $this->renderFailure(
                'validation_failed',
                'A public hostname is required.',
                ['field' => 'host'],
            );
        }

        $node = $this->resolveNode();

        if (is_int($node)) {
            return $node;
        }

        $payload = $this->filledQuery([
            'host' => $host,
            'node' => $node,
        ]);

        return $this->streamProgress(
            '/api/s3/public-hosts',
            $payload,
            fn (ProgressEventType $type, array $frame): int => $this->renderProgressTerminalFrame($type, $frame),
        );
    }

    private function resolveHost(): ?string
    {
        $host = $this->stringArgument('host');

        if ($host !== null) {
            return $host;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Public hostname (e.g. s3.example.com)', required: true));
        }

        return null;
    }

    /**
     * Resolve the target s3 node.
     *
     * D11: --node defaults to the single visible active s3 node when exactly one exists.
     * In interactive mode when no --node is given and multiple nodes exist, prompt via datatable.
     *
     * @return string|int Node name, or int exit code on failure.
     */
    private function resolveNode(): string|int
    {
        $node = $this->stringOption('node');

        if ($node !== null) {
            return $node;
        }

        // Fetch active s3 nodes from the gateway to auto-resolve or prompt.
        try {
            $response = $this->gatewayGet('/api/nodes', ['role' => 's3']);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $nodes = $this->extractNodeNames($response);

        if ($nodes === []) {
            return $this->renderFailure(
                'validation_failed',
                'An active s3 role node is required to publish an S3 host.',
                ['field' => 'node', 'required_role' => 's3'],
            );
        }

        if (count($nodes) === 1) {
            return $nodes[0];
        }

        // Multiple nodes: interactive datatable selection or fail non-interactively.
        if ($this->isInteractiveInput()) {
            return $this->promptNodeSelection($nodes);
        }

        return $this->renderFailure(
            'validation_failed',
            'An active s3 role node is required to publish an S3 host.',
            ['field' => 'node', 'required_role' => 's3'],
        );
    }

    /**
     * @param  list<string>  $nodes
     */
    private function promptNodeSelection(array $nodes): string
    {
        $this->line('');
        $this->line('<comment>Available S3 nodes:</comment>');

        table(['Node'], array_map(fn (string $n): array => [$n], $nodes));

        return trim(text(
            label: 'Select S3 node',
            required: true,
            validate: fn (string $v): ?string => in_array(trim($v), $nodes, true)
                ? null
                : 'Select a node from the list above.',
        ));
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    /**
     * Extract node names from the gateway node list response.
     *
     * @return list<string>
     */
    private function extractNodeNames(array $response): array
    {
        $nodes = $response['success']['data']['nodes'] ?? [];

        if (! is_array($nodes)) {
            return [];
        }

        $names = [];

        foreach ($nodes as $node) {
            if (is_array($node) && is_string($node['name'] ?? null) && $node['name'] !== '') {
                $names[] = $node['name'];
            }
        }

        return $names;
    }
}
