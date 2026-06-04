<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;
use Orbit\Core\Progress\ProgressEventType;

abstract class ToolGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use StreamsGatewayProgress;

    /**
     * @var list<string>
     */
    private const array INSTANCE_SELECTABLE_TOOLS = [
        'mysql',
        'postgres',
        'redis',
        'mailpit',
        'reverb',
        'rustfs',
        'openclaw',
        'hermes',
    ];

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function requireToolArgument(string $message = 'A tool name is required.'): string|int
    {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            if (! $this->wantsJson() && $this->input->isInteractive()) {
                $answer = $this->ask('Tool name');

                if (is_string($answer) && trim($answer) !== '') {
                    return trim($answer);
                }
            }

            return $this->failValidation('tool', $message);
        }

        return $tool;
    }

    /**
     * @return array<string, string>|int
     */
    protected function toolTargetPayload(bool $requireTarget = false): array|int
    {
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');

        if ($node === null && $app === null) {
            try {
                $node = app(OrbitConfigStore::class)->defaultNode();
            } catch (OrbitConfigStoreException $exception) {
                return $this->renderFailure($exception->orbitCode, $exception->getMessage());
            }
        }

        $payload = $this->filledQuery([
            'app' => $app,
            'node' => $node,
        ]);

        if ($requireTarget && $payload === []) {
            if (! $this->wantsJson() && $this->input->isInteractive()) {
                $answer = $this->ask('Target node');

                if (is_string($answer) && trim($answer) !== '') {
                    return ['node' => trim($answer)];
                }
            }

            return $this->renderFailure(
                'validation_failed',
                'A node or app target is required.',
                ['fields' => ['target']],
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function streamToolAction(string $tool, string $action, array $payload): int
    {
        return $this->streamProgress(
            '/api/tools/'.rawurlencode($tool).'/'.$action,
            $payload,
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function streamToolBulkAction(string $action, array $payload): int
    {
        return $this->streamProgress(
            '/api/tools/'.$action,
            $payload,
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, string>|int
     */
    protected function resolveToolInstancePayload(string $tool, array $payload): array|int
    {
        $explicitInstance = $this->stringOption('instance');

        if ($explicitInstance !== null) {
            return ['instance' => $explicitInstance];
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return [];
        }

        if (! in_array($this->normalizedToolName($tool), self::INSTANCE_SELECTABLE_TOOLS, true)) {
            return [];
        }

        try {
            $response = $this->gatewayGet('/api/tools', $payload);
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $choices = $this->toolInstanceChoices($response, $tool);

        if ($choices === []) {
            return [];
        }

        if (count($choices) === 1) {
            return $this->selectedToolInstancePayload($payload, array_first($choices));
        }

        $selected = $this->choice('Instance', array_keys($choices));

        if (! is_string($selected) || ! array_key_exists($selected, $choices)) {
            return $this->failValidation('instance', 'Operation cancelled.', ['reason' => 'cancelled']);
        }

        return $this->selectedToolInstancePayload($payload, $choices[$selected]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{instance: string, node: string|null}>
     */
    private function toolInstanceChoices(array $response, string $tool): array
    {
        $choices = [];

        foreach ($this->toolPayloads($response) as $toolPayload) {
            $name = $this->toolString($toolPayload['name'] ?? null);

            if ($name !== $tool) {
                continue;
            }

            $instance = $this->toolString($toolPayload['instance'] ?? null);

            if ($instance === null) {
                continue;
            }

            $choices[$this->uniqueToolChoiceLabel($instance, $choices)] = [
                'instance' => $instance,
                'node' => $this->toolString($toolPayload['node'] ?? null),
            ];
        }

        return $choices;
    }

    /**
     * @param  array<string, string>  $payload
     * @param  array{instance: string, node: string|null}  $selection
     * @return array<string, string>
     */
    private function selectedToolInstancePayload(array $payload, array $selection): array
    {
        $selectionPayload = [
            'instance' => $selection['instance'],
        ];

        if (($payload['node'] ?? null) === null && ($payload['app'] ?? null) === null && $selection['node'] !== null) {
            $selectionPayload['node'] = $selection['node'];
        }

        return $selectionPayload;
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

    /**
     * @param  array<string, array{instance: string, node: string|null}>  $choices
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

    private function normalizedToolName(string $tool): string
    {
        return mb_strtolower(trim($tool));
    }
}
