<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\Gateway\GatewayRequestSender;
use App\Services\Gateway\Requests\UpdateNodeRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Throwable;

#[Signature('node:update
    {name? : Node name to update}
    {--host= : New SSH/bootstrap endpoint}
    {--environment= : New environment (development/production)}
    {--public-ipv4= : Public IPv4 address metadata}
    {--public-ipv6= : Public IPv6 address metadata}
    {--json : Output as JSON}')]
#[Description('Update node registry metadata and role-owned settings')]
class NodeUpdateCommand extends Command
{
    public function handle(): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
            );
        }

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Node name is required.',
                meta: ['field' => 'name'],
            );
        }

        $duplicateField = $this->detectDuplicateFieldFlag();

        if ($duplicateField !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: "Field '{$duplicateField}' was supplied more than once.",
                meta: ['field' => $duplicateField],
            );
        }

        $providedFields = $this->getProvidedFields();

        if ($providedFields === []) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'At least one field must be provided to update a node.',
                meta: ['field' => 'fields'],
            );
        }

        $fieldValidationError = $this->validateFieldValues($providedFields);

        if ($fieldValidationError !== null) {
            return $this->failCommand(
                code: $fieldValidationError['code'],
                message: $fieldValidationError['message'],
                meta: $fieldValidationError['meta'],
            );
        }

        if ($callerRole === 'control') {
            return $this->forwardUpdate($name, $providedFields);
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->failCommand(
                code: 'node.not_found',
                message: "Node '{$name}' not found.",
                meta: ['name' => $name],
            );
        }

        $roleIncompatible = $this->detectRoleIncompatibleField($node, $providedFields);

        if ($roleIncompatible !== null) {
            return $this->failCommand(
                code: 'node.field_role_incompatible',
                message: "The field '{$roleIncompatible['field']}' is not valid for node '{$name}' (role: {$roleIncompatible['role']}).",
                meta: [
                    'field' => $roleIncompatible['field'],
                    'name' => $name,
                    'role' => $roleIncompatible['role'],
                ],
            );
        }

        $this->renderProgressTree();

        $changes = $this->computeChanges($node, $providedFields);

        if ($changes === []) {
            return $this->respondSuccess($name, []);
        }

        $node->update($changes);

        $changedKeys = array_keys($changes);

        return $this->respondSuccess($name, $changedKeys);
    }

    /**
     * @param  array<string, string|null>  $providedFields
     */
    private function forwardUpdate(string $name, array $providedFields): int
    {
        $this->renderProgressTree();

        try {
            $response = GatewayRequestSender::make()->send(
                new UpdateNodeRequest($name, $providedFields),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update a node.',
                meta: [],
            );
        }

        if (! $response->isSuccess()) {
            return $this->failCommand(
                code: $response->errorCode() ?? 'gateway_unavailable',
                message: $response->errorMessage() ?? 'Gateway connection is required to update a node.',
                meta: $response->errorMeta(),
            );
        }

        return $this->respondForwardedSuccess($name, $response->data());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respondForwardedSuccess(string $fallbackName, array $data): int
    {
        $name = $data['name'] ?? $fallbackName;
        $changed = $data['changed'] ?? [];

        return $this->respondSuccess(
            is_string($name) && $name !== '' ? $name : $fallbackName,
            is_array($changed) ? array_values(array_filter($changed, is_string(...))) : [],
        );
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function detectDuplicateFieldFlag(): ?string
    {
        $flags = ['host', 'environment', 'public-ipv4', 'public-ipv6'];

        if (! $this->input instanceof ArgvInput) {
            return null;
        }

        $tokens = $this->input->getRawTokens();
        $counts = array_fill_keys($flags, 0);

        foreach ($tokens as $token) {
            foreach ($flags as $flag) {
                if ($token === "--{$flag}" || str_starts_with((string) $token, "--{$flag}=")) {
                    $counts[$flag]++;
                }
            }
        }

        foreach ($counts as $flag => $count) {
            if ($count > 1) {
                return $flag;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|null>
     */
    private function getProvidedFields(): array
    {
        $fields = [];

        if ($this->option('host') !== null) {
            $fields['host'] = (string) $this->option('host');
        }

        if ($this->option('environment') !== null) {
            $fields['environment'] = (string) $this->option('environment');
        }

        if ($this->option('public-ipv4') !== null) {
            $fields['public_ipv4'] = (string) $this->option('public-ipv4');
        }

        if ($this->option('public-ipv6') !== null) {
            $fields['public_ipv6'] = (string) $this->option('public-ipv6');
        }

        return $fields;
    }

    /**
     * @param  array<string, string|null>  $providedFields
     * @return array{code: string, message: string, meta: array<string, mixed>}|null
     */
    private function validateFieldValues(array $providedFields): ?array
    {
        foreach ($providedFields as $field => $value) {
            if ($value === '' || $value === null) {
                return [
                    'code' => 'validation_failed',
                    'message' => "Field '{$field}' cannot be empty.",
                    'meta' => ['field' => $field],
                ];
            }
        }

        if (isset($providedFields['environment']) && ! in_array($providedFields['environment'], ['development', 'production'], true)) {
            return [
                'code' => 'validation_failed',
                'message' => "Invalid value for --environment: '{$providedFields['environment']}'. Allowed values: development, production.",
                'meta' => [
                    'field' => 'environment',
                    'value' => $providedFields['environment'],
                    'allowed' => ['development', 'production'],
                ],
            ];
        }

        if (isset($providedFields['public_ipv4']) && filter_var($providedFields['public_ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [
                'code' => 'validation_failed',
                'message' => "Invalid IPv4 address: '{$providedFields['public_ipv4']}'.",
                'meta' => [
                    'field' => 'public_ipv4',
                    'value' => $providedFields['public_ipv4'],
                ],
            ];
        }

        if (isset($providedFields['public_ipv6']) && filter_var($providedFields['public_ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return [
                'code' => 'validation_failed',
                'message' => "Invalid IPv6 address: '{$providedFields['public_ipv6']}'.",
                'meta' => [
                    'field' => 'public_ipv6',
                    'value' => $providedFields['public_ipv6'],
                ],
            ];
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $providedFields
     * @return array<string, mixed>|null
     */
    private function detectRoleIncompatibleField(Node $node, array $providedFields): ?array
    {
        $role = $node->role;

        if (isset($providedFields['environment']) && $role !== 'app') {
            return ['field' => 'environment', 'role' => $role];
        }

        if (isset($providedFields['host']) && $role === 'control') {
            return ['field' => 'host', 'role' => $role];
        }

        if (isset($providedFields['public_ipv4']) && $role === 'control') {
            return ['field' => 'public_ipv4', 'role' => $role];
        }

        if (isset($providedFields['public_ipv6']) && $role === 'control') {
            return ['field' => 'public_ipv6', 'role' => $role];
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $providedFields
     * @return array<string, mixed>
     */
    private function computeChanges(Node $node, array $providedFields): array
    {
        $changes = [];

        if (isset($providedFields['host']) && $providedFields['host'] !== $node->host) {
            $changes['host'] = $providedFields['host'];
        }

        if (isset($providedFields['environment']) && $providedFields['environment'] !== $node->environment) {
            $changes['environment'] = $providedFields['environment'];
        }

        if (isset($providedFields['public_ipv4']) && $providedFields['public_ipv4'] !== $node->public_ipv4) {
            $changes['public_ipv4'] = $providedFields['public_ipv4'];
        }

        if (isset($providedFields['public_ipv6']) && $providedFields['public_ipv6'] !== $node->public_ipv6) {
            $changes['public_ipv6'] = $providedFields['public_ipv6'];
        }

        return $changes;
    }

    /**
     * @param  list<string>  $changed
     */
    private function respondSuccess(string $name, array $changed): int
    {
        $data = [
            'name' => $name,
            'changed' => $changed,
            'action' => 'updated',
        ];

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $footer = $changed === [] ? "Node '{$name}' unchanged" : "Node '{$name}' updated";
        $this->line("└ {$footer}");
        $this->line('');

        if ($changed === []) {
            $this->line("Node '{$name}' unchanged");
            $this->line('  No fields were modified.');
        } else {
            $this->line("Node '{$name}' updated");
            $this->line('  Changed: '.implode(', ', $changed));
        }

        return self::SUCCESS;
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
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function renderProgressTree(): void
    {
        if ($this->wantsJson()) {
            return;
        }

        $this->line('┌ Update Node');
        $this->line('○ Validate node');
        $this->line('○ Update intent');
    }
}
