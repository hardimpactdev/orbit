<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;
use App\Support\Prompts\PropertyList;

final class ToolListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'tool:list
        {--instance= : Filter by instance selector}
        {--node= : Filter by owning node}
        {--all : Show tools across all visible nodes}
        {--json}';

    #[\Override]
    protected $description = 'List tool state tracked by the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/tools', $this->toolListQuery());
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $tools = $this->toolsFromGatewayResponse($response);

        if ($tools === []) {
            $this->line('No tools found.');

            return self::SUCCESS;
        }

        $this->renderTools($tools);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListQuery(): array
    {
        return new ToolListQueryResolver(
            instance: $this->stringOption('instance'),
            node: $this->stringOption('node'),
            defaultNode: app(OrbitConfigStore::class)->defaultNode(),
            all: (bool) $this->option('all'),
            gatewayGet: $this->gatewayGet(...),
        )->resolve();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function toolsFromGatewayResponse(array $response): array
    {
        $tools = $response['success']['data']['tools'] ?? null;

        if (! is_array($tools)) {
            return [];
        }

        return array_values(array_filter($tools, is_array(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function renderTools(array $tools): void
    {
        new PropertyList($this->toolPropertyListGroups($tools))->display();
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<array{
     *     heading: string,
     *     items: list<array{
     *         label: string,
     *         properties: array<string, string>,
     *     }>,
     * }>
     */
    private function toolPropertyListGroups(array $tools): array
    {
        $grouped = [];

        foreach ($tools as $tool) {
            $node = $this->toolString($tool, 'node');
            $grouped[$node][] = $tool;
        }

        ksort($grouped);

        return array_map(
            fn (string $node, array $nodeTools): array => [
                'heading' => "Node: {$node}",
                'items' => array_map(fn (array $tool): array => [
                    'label' => $this->toolString($tool, 'name'),
                    'properties' => [
                        'Expected' => $this->toolString($tool, 'expected_state'),
                        'Managed' => $this->toolString($tool, 'managed'),
                        'Version' => $this->toolString($tool, 'version'),
                    ],
                ], $nodeTools),
            ],
            array_keys($grouped),
            array_values($grouped),
        );
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    private function toolString(array $tool, string $key): string
    {
        $value = $tool[$key] ?? null;

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
