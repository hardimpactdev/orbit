<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Firewall\StoreFirewallRuleRequest;
use App\Http\Gateway\Responses\Firewall\FirewallRuleMutationResponse;
use App\Models\LocalNodeDefault;
use App\Services\Firewall\FirewallRuleIntent;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Command;
use Throwable;

abstract class AbstractFirewallStoreCommand extends Command
{
    abstract protected function firewallAction(): string;

    public function handle(FirewallRuleIntent $intent, CallerRoleResolver $callerRoleResolver): int
    {
        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        try {
            if ($callerRoleResolver->resolve() !== 'gateway') {
                return $this->forwardStore($input);
            }

            $result = $intent->store(
                action: $this->firewallAction(),
                name: $input['name'],
                nodeName: $input['node'],
                direction: $input['direction'],
                source: $input['source'],
                destination: $input['destination'],
                port: $input['port'],
                protocol: $input['protocol'],
                reason: $input['reason'],
            );
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to manage firewall rules.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to manage firewall rules.',
                meta: [],
            );
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    /**
     * @param  array{name: string, node: string, direction: string, source: string, destination: ?string, port: string, protocol: string, reason: ?string}  $input
     */
    private function forwardStore(array $input): int
    {
        /** @var FirewallRuleMutationResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new StoreFirewallRuleRequest(
                action: $this->firewallAction(),
                name: $input['name'],
                node: $input['node'],
                direction: $input['direction'],
                source: $input['source'],
                destination: $input['destination'],
                port: $input['port'],
                protocol: $input['protocol'],
                reason: $input['reason'],
            ))
            ->dto();

        return $this->successPayload($dto->data, $dto->meta);
    }

    /**
     * @return array{name: string, node: string, direction: string, source: string, destination: ?string, port: string, protocol: string, reason: ?string}|int
     */
    private function validatedInput(): array|int
    {
        $name = $this->stringArgument('name');
        $node = $this->stringOption('node') ?? $this->defaultNodeName();
        $port = $this->stringOption('port');

        if ($name === null) {
            return $this->failValidation('name', 'The firewall rule name is required.');
        }

        if ($node === null) {
            return $this->failValidation('node', 'A firewall target node is required.');
        }

        if ($port === null) {
            return $this->failValidation('port', 'The firewall rule port is required.');
        }

        return [
            'name' => $name,
            'node' => $node,
            'direction' => $this->stringOption('direction') ?? 'incoming',
            'source' => $this->stringOption('from') ?? 'any',
            'destination' => $this->stringOption('to'),
            'port' => $port,
            'protocol' => $this->stringOption('protocol') ?? 'tcp',
            'reason' => $this->stringOption('reason'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function successPayload(array $data, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $rule = is_array($data['rule'] ?? null) ? $data['rule'] : [];
        $this->line('┌ Managing Firewall Rule');
        $this->line('○ Validate firewall target');
        $this->line('○ Check baseline policy boundary');
        $this->line('○ Apply and verify firewall rule');
        $this->line('└ Firewall rule intent saved');
        $this->line("Firewall rule '".(string) ($rule['name'] ?? '')."' saved on node '".(string) ($rule['node'] ?? '')."'.");

        $this->renderWarnings($meta);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function renderWarnings(array $meta): void
    {
        $warnings = is_array($meta['warnings'] ?? null) ? $meta['warnings'] : [];

        foreach ($warnings as $warning) {
            if (is_array($warning)) {
                $this->line('  Drift detected: '.(string) ($warning['code'] ?? 'warning'));
            }
        }
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand('validation_failed', $message, ['field' => $field]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function defaultNodeName(): ?string
    {
        $name = LocalNodeDefault::query()->value('default_node_name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
