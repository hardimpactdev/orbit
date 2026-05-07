<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Firewall\RemoveFirewallRuleRequest;
use App\Http\Gateway\Responses\Firewall\FirewallRuleMutationResponse;
use App\Models\LocalNodeDefault;
use App\Services\Firewall\FirewallRuleIntent;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;

#[Signature('firewall:remove
    {name? : Firewall rule name}
    {--node= : Target node}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove firewall rule intent')]
class FirewallRemoveCommand extends Command
{
    public function handle(FirewallRuleIntent $intent, CallerRoleResolver $callerRoleResolver): int
    {
        $name = $this->stringArgument('name');
        $node = $this->stringOption('node') ?? $this->defaultNodeName();

        if ($name === null) {
            return $this->failValidation('name', 'The firewall rule name is required.');
        }

        if ($node === null) {
            return $this->failValidation('node', 'A firewall target node is required.');
        }

        $consent = $this->confirmRemoval($name, $node);

        if (is_int($consent)) {
            return $consent;
        }

        try {
            if ($callerRoleResolver->resolve() !== 'gateway') {
                return $this->forwardRemove($name, $node);
            }

            $result = $intent->remove($name, $node);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to remove firewall rules.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to remove firewall rules.',
                meta: [],
            );
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    private function forwardRemove(string $name, string $node): int
    {
        /** @var FirewallRuleMutationResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RemoveFirewallRuleRequest(name: $name, node: $node))
            ->dto();

        return $this->successPayload($dto->data, $dto->meta);
    }

    private function confirmRemoval(string $name, string $node): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failCommand(
                code: 'destructive_consent_required',
                message: 'Use --force to remove this firewall rule.',
                meta: ['field' => 'force'],
            );
        }

        if (confirm(label: "Remove firewall rule '{$name}' from {$node}?", default: false)) {
            return null;
        }

        return $this->failCommand(
            code: 'destructive_consent_required',
            message: 'Operation cancelled.',
            meta: ['field' => 'force'],
        );
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
        $this->line('┌ Removing Firewall Rule');
        $this->line('○ Confirm destructive removal');
        $this->line('○ Resolve firewall rule');
        $this->line('○ Remove backend firewall rule');
        $this->line('└ Firewall rule intent removed');
        $this->line("Firewall rule '".(string) ($rule['name'] ?? '')."' removed from node '".(string) ($rule['node'] ?? '')."'.");

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

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
