<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class ToolCredentialsCommand extends GatewayCommand
{
    use ResolvesHostContext;

    private ?string $resolvedToolNode = null;

    #[\Override]
    protected $signature = 'tool:credentials
        {tool? : Tool catalog name to read credentials for}
        {--instance= : Resolve target by instance selector}
        {--node= : Resolve target by node}
        {--json}';

    #[\Override]
    protected $description = 'Read managed tool credentials.';

    public function handle(): int
    {
        $tool = $this->resolveToolName();

        if (is_int($tool)) {
            return $tool;
        }

        try {
            $response = $this->gatewayGet('/api/tools/'.rawurlencode($tool).'/credentials', $this->filledQuery([
                'instance' => $this->stringOption('instance'),
                'node' => $this->resolvedToolNode(),
            ]));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderCredentialJson($response);
        }

        $this->renderCredentials($response);

        return self::SUCCESS;
    }

    private function resolveToolName(): string|int
    {
        $tool = $this->stringArgument('tool');

        if ($tool !== null) {
            return $tool;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->renderFailure('validation_failed', 'The tool argument is required.', ['field' => 'tool']);
        }

        try {
            $response = $this->gatewayGet('/api/tools', $this->filledQuery([
                'instance' => $this->stringOption('instance'),
                'node' => $this->targetNodeOptionOrDefault(),
            ]));
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $choices = $this->toolChoices($response);

        if ($choices === []) {
            return $this->renderFailure('tool.not_found', 'No credential-bearing tools found for the selected target.');
        }

        $selected = $this->choice('Which credential-bearing tool do you want to inspect?', array_keys($choices));

        if (! is_string($selected) || ! array_key_exists($selected, $choices)) {
            return $this->renderFailure('validation_failed', 'Operation cancelled.');
        }

        $selection = $choices[$selected];
        $this->resolvedToolNode = $selection['node'];

        return $selection['name'];
    }

    private function resolvedToolNode(): ?string
    {
        return $this->stringOption('node') ?? $this->resolvedToolNode ?? $this->targetNodeOptionOrDefault();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{name: string, node: string|null}>
     */
    private function toolChoices(array $response): array
    {
        $tools = $this->toolPayloads($response);
        $nameCounts = $this->toolNameCounts($tools);
        $choices = [];

        foreach ($tools as $tool) {
            $name = $this->toolString($tool['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $node = $this->toolString($tool['node'] ?? null);
            $label =
                $nameCounts[$name] > 1 && $node !== null
                    ? "{$name} (node: {$node})"
                    : $name;

            $choices[$this->uniqueToolChoiceLabel($label, $choices)] = [
                'name' => $name,
                'node' => $node,
            ];
        }

        return $choices;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function toolPayloads(array $response): array
    {
        $data = $this->successData($response);
        $tools = $data['tools'] ?? [];

        if (! is_array($tools)) {
            return [];
        }

        return array_values(array_filter($tools, is_array(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, int>
     */
    private function toolNameCounts(array $tools): array
    {
        $counts = [];

        foreach ($tools as $tool) {
            $name = $this->toolString($tool['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, array{name: string, node: string|null}>  $choices
     */
    private function uniqueToolChoiceLabel(string $label, array $choices): string
    {
        if (! array_key_exists($label, $choices)) {
            return $label;
        }

        $counter = 2;

        while (array_key_exists("{$label} #{$counter}", $choices)) {
            $counter++;
        }

        return "{$label} #{$counter}";
    }

    private function toolString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderCredentialJson(array $response): int
    {
        $data = $this->successData($response);
        $credentials = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];

        return $this->renderSuccess(['credentials' => $credentials]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderCredentials(array $response): void
    {
        $data = $this->successData($response);
        $credentials = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];
        $tool = is_string($credentials['tool'] ?? null) ? $credentials['tool'] : 'tool';
        $node = is_string($credentials['node'] ?? null) ? $credentials['node'] : 'node';
        $fields = is_array($credentials['fields'] ?? null) ? $credentials['fields'] : [];

        $this->line("Credentials for {$tool} on {$node}:");

        foreach ($fields as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $this->line("  {$key}: {$value}");
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }
}
