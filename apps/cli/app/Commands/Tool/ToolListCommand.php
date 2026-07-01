<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;

final class ToolListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'tool:list
        {--app= : Filter by app selector}
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

        new ToolListDataListRenderer($this)->render($tools);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListQuery(): array
    {
        return new ToolListQueryResolver(
            app: $this->stringOption('app'),
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
}
