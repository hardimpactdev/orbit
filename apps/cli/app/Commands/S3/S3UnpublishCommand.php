<?php

declare(strict_types=1);

namespace App\Commands\S3;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use Orbit\Core\Progress\ProgressEventType;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

final class S3UnpublishCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 's3:unpublish
        {host? : Public S3 hostname to remove (e.g. s3.example.com)}
        {--node= : Active s3 node to unpublish from}
        {--force : Confirm destructive removal without prompting}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Remove a public HTTPS hostname from the fleet S3 service.';

    public function handle(): int
    {
        $node = $this->resolveNode();

        if (is_int($node)) {
            return $node;
        }

        $host = $this->resolveHost();

        if ($host === null) {
            return $this->renderFailure(
                'validation_failed',
                'A public hostname is required.',
                ['field' => 'host'],
            );
        }

        $consent = $this->confirmDestructiveRemoval($host);

        if ($consent !== null) {
            return $consent;
        }

        $payload = $this->filledQuery([
            'node' => $node,
        ]);

        return $this->streamProgress(
            "/api/s3/public-hosts/{$host}",
            $payload,
            fn (ProgressEventType $type, array $frame): int => $this->renderProgressTerminalFrame($type, $frame),
            'delete',
        );
    }

    private function resolveHost(): ?string
    {
        $host = $this->stringArgument('host');

        if ($host !== null) {
            return $host;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Public hostname to remove (e.g. s3.example.com)', required: true));
        }

        return null;
    }

    /**
     * Resolve the target s3 node.
     *
     * --node defaults to the single visible active s3 node when exactly one exists.
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

        try {
            $response = $this->gatewayGet('/api/nodes', ['role' => 's3']);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $nodes = $this->extractNodeNames($response);

        if ($nodes === []) {
            return $this->renderFailure(
                'validation_failed',
                'An active s3 role node is required to unpublish an S3 host.',
                ['field' => 'node', 'required_role' => 's3'],
            );
        }

        if (count($nodes) === 1) {
            return $nodes[0];
        }

        if ($this->isInteractiveInput()) {
            return $this->promptNodeSelection($nodes);
        }

        return $this->renderFailure(
            'validation_failed',
            'An active s3 role node is required to unpublish an S3 host.',
            ['field' => 'node', 'required_role' => 's3'],
        );
    }

    /**
     * Require destructive consent before removal.
     *
     * Returns null when consent is obtained (proceed), or an int exit code to abort.
     */
    private function confirmDestructiveRemoval(string $host): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsMachineJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure(
                'validation_failed',
                '--force is required to unpublish an S3 host in non-interactive mode.',
                ['field' => 'force', 'reason' => 'destructive_consent_required'],
            );
        }

        if (confirm(label: "Remove public S3 host '{$host}'?", default: false)) {
            return null;
        }

        return $this->renderFailure(
            'validation_failed',
            'Removal aborted. No changes were made.',
            ['field' => 'force', 'reason' => 'destructive_consent_required'],
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
        return $this->allowsInteractiveInput();
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
