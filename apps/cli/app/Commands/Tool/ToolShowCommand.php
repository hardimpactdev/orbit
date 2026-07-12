<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\RendersShowDetails;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class ToolShowCommand extends GatewayCommand
{
    // @orbit-ssh-lane transitional-ssh
    use RendersShowDetails;
    use ResolvesHostContext;

    private const string TRANSITIONAL_SSH_FALLBACK = 'transitional-ssh-fallback';

    private const array SUPPORTED_NODE_TRANSPORT_PREFERENCES = [
        self::TRANSITIONAL_SSH_FALLBACK,
    ];

    #[\Override]
    protected $signature = 'tool:show
        {tool? : Tool catalog name to inspect}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--node-transport= : Exact transitional SSH opt-in for --live (transitional-ssh-fallback)}
        {--live : Request gateway live inspection}
        {--json}';

    #[\Override]
    protected $description = 'Show one tool tracked by the gateway registry.';

    public function handle(): int
    {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            return $this->renderFailure('validation_failed', 'The tool argument is required.', ['field' => 'tool']);
        }

        $live = $this->option('live') === true;
        $nodeTransport = $this->stringOption('node-transport');

        if (
            $nodeTransport !== null
            && ! in_array($nodeTransport, self::SUPPORTED_NODE_TRANSPORT_PREFERENCES, strict: true)
        ) {
            return $this->renderFailure(
                'validation_failed',
                "Invalid node transport preference [{$nodeTransport}].",
                [
                    'field' => 'node-transport',
                    'allowed' => self::SUPPORTED_NODE_TRANSPORT_PREFERENCES,
                ],
            );
        }

        if (! $live && $nodeTransport !== null) {
            return $this->renderFailure(
                'validation_failed',
                'The --node-transport option is only supported with --live.',
                ['field' => 'node-transport'],
            );
        }

        if ($live && $nodeTransport !== self::TRANSITIONAL_SSH_FALLBACK) {
            return $this->renderFailure(
                'node_transport_required',
                'tool:show --live still uses RemoteShell and requires explicit --node-transport=transitional-ssh-fallback until it is migrated to agent-push.',
                [
                    'field' => 'node-transport',
                    'required' => self::TRANSITIONAL_SSH_FALLBACK,
                ],
            );
        }

        try {
            $response = $this->gatewayGet('/api/tools/'.rawurlencode($tool), $this->filledQuery([
                'app' => $this->stringOption('app'),
                'node' => $this->targetNodeOptionOrDefault(),
                'live' => $live ? '1' : null,
            ]));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $tool = $this->toolFromGatewayResponse($response);

        if ($tool === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required tool data.');
        }

        $this->renderTool($tool);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function toolFromGatewayResponse(array $response): ?array
    {
        $tool = $response['success']['data']['tool'] ?? null;

        return is_array($tool) ? $tool : null;
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    private function renderTool(array $tool): void
    {
        $properties = [
            'Node' => $tool['node'] ?? null,
            'Expected' => $tool['expected_state'] ?? $tool['state'] ?? null,
            'Version' => $tool['version'] ?? null,
            'Managed' => $tool['managed'] ?? null,
            'Endpoints' => $this->endpointLabels($tool['endpoints'] ?? []),
        ];

        if (($tool['observed_state'] ?? null) !== null) {
            $properties['Observed'] = $tool['observed_state'];
        }

        $this->renderShowDetails('Tool: '.(string) ($tool['name'] ?? ''), $properties);
    }

    private function endpointLabels(mixed $endpoints): ?string
    {
        if (! is_array($endpoints) || $endpoints === []) {
            return null;
        }

        $labels = [];

        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $name = is_string($endpoint['name'] ?? null) ? $endpoint['name'] : 'endpoint';
            $host = is_string($endpoint['host'] ?? null) ? $endpoint['host'] : null;
            $port = is_scalar($endpoint['port'] ?? null) ? (string) $endpoint['port'] : null;

            $labels[] =
                $host !== null && $port !== null
                    ? "{$name} ({$host}:{$port})"
                    : $name;
        }

        return $labels === [] ? null : implode(', ', $labels);
    }
}
