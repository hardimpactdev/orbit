<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class ToolListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'tool:list
        {--app= : Filter by app selector}
        {--node= : Filter by owning node}
        {--json}';

    #[\Override]
    protected $description = 'List tool state tracked by the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/tools', $this->filledQuery([
                'app' => $this->stringOption('app'),
                'node' => $this->stringOption('node'),
            ]));
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

        foreach ($this->toolsGroupedByNode($tools) as $node => $nodeTools) {
            $this->line("Node: {$node}");

            table(
                headers: ['TOOL', 'EXPECTED', 'MANAGED', 'VERSION'],
                rows: array_map(fn (array $tool): array => [
                    $this->toolString($tool, 'name'),
                    $this->toolString($tool, 'expected_state'),
                    $this->toolBoolean($tool, 'managed'),
                    $this->toolString($tool, 'version'),
                ], $nodeTools),
            );
        }

        return self::SUCCESS;
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
     * @return array<string, list<array<string, mixed>>>
     */
    private function toolsGroupedByNode(array $tools): array
    {
        $grouped = [];

        foreach ($tools as $tool) {
            $node = $this->toolString($tool, 'node');
            $grouped[$node][] = $tool;
        }

        ksort($grouped);

        return $grouped;
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

    /**
     * @param  array<string, mixed>  $tool
     */
    private function toolBoolean(array $tool, string $key): string
    {
        $value = $tool[$key] ?? null;

        return is_bool($value) ? ($value ? 'yes' : 'no') : '—';
    }
}
