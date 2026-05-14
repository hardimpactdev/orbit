<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\UpdateNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeUpdateResponse;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Throwable;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('node:update
    {name? : Node name to update}
    {--host= : New SSH/bootstrap endpoint}
    {--environment= : New environment (development/production)}
    {--tld= : Development TLD for development app nodes}
    {--public-ipv4= : Public IPv4 address metadata}
    {--public-ipv6= : Public IPv6 address metadata}
    {--json : Output as JSON}')]
#[Description('Update node registry metadata and role-owned settings')]
class NodeUpdateCommand extends Command
{
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    public function handle(ReenactNodeArtifacts $reenactNodeArtifacts): int
    {
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        if ($callerRole === 'control' && ! $this->hasConfiguredGateway()) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update a node.',
                meta: [],
            );
        }

        $name = $this->resolveName($callerRole);

        if ($name instanceof GatewayApiException) {
            return $this->failCommand(
                code: $name->errorCode() ?? 'gateway_unavailable',
                message: $name->getMessage(),
                meta: $name->errorMeta(),
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

        if ($name === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Node name is required.',
                meta: ['field' => 'name'],
            );
        }

        $node = null;

        if ($callerRole === 'gateway') {
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
        }

        $providedFields = $this->getProvidedFields();

        if ($providedFields === []) {
            if ($this->isInteractiveInput()) {
                $providedFields = $this->promptForFieldUpdate($node);

                if ($providedFields === []) {
                    return $this->failCommand(
                        code: 'validation_failed',
                        message: 'At least one field must be provided to update a node.',
                        meta: ['field' => 'fields'],
                    );
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'At least one field must be provided to update a node.',
                    meta: ['field' => 'fields'],
                );
            }
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

        assert($node instanceof Node);

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

        if (
            isset($providedFields['tld'])
            && $providedFields['tld'] !== $node->tld
            && Node::query()
                ->where('tld', $providedFields['tld'])
                ->where('status', 'active')
                ->where('id', '!=', $node->id)
                ->exists()
        ) {
            return $this->failCommand(
                code: 'node.tld_in_use',
                message: "Development TLD '{$providedFields['tld']}' is already assigned to another node.",
                meta: [
                    'field' => 'tld',
                    'value' => $providedFields['tld'],
                ],
            );
        }

        $changes = [];
        $warnings = [];
        $operation = function () use ($node, $providedFields, $reenactNodeArtifacts, &$changes, &$warnings): string {
            $changes = $this->computeChanges($node, $providedFields);

            if ($changes === []) {
                return 'unchanged';
            }

            $node->update($changes);
            $changedKeys = array_keys($changes);
            $warnings = $this->reenactNodeArtifacts($reenactNodeArtifacts, $node->refresh(), $changedKeys);

            return $warnings === [] ? 'updated' : 'updated with drift';
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runNodeUpdateTree($name, $operation);

            if ($exitCode !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $operation();
        }

        return $this->respondSuccess($name, array_keys($changes), $warnings);
    }

    private function resolveName(string $callerRole): string|GatewayApiException|null
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->isInteractiveInput()) {
            return $this->promptNodeName();
        }

        return null;
    }

    private function promptNodeName(): string|GatewayApiException
    {
        try {
            return $this->promptForVisibleNode(label: 'Select a node to update');
        } catch (PromptAborted) {
            return new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function promptForFieldUpdate(?Node $node): array
    {
        $choices = $this->interactiveFieldChoices($node);

        if ($choices === []) {
            return [];
        }

        $field = select(
            label: 'Which field would you like to update?',
            options: $choices,
            required: true,
        );

        return [
            $field => $this->promptForFieldValue($field),
        ];
    }

    /**
     * @return list<string>
     */
    private function interactiveFieldChoices(?Node $node): array
    {
        if (! $node instanceof Node) {
            return ['host', 'environment', 'tld', 'public_ipv4', 'public_ipv6'];
        }

        return match ($node->role) {
            'app' => $node->environment === 'development'
                ? ['host', 'environment', 'tld', 'public_ipv4', 'public_ipv6']
                : ['host', 'environment', 'public_ipv4', 'public_ipv6'],
            'gateway' => ['host', 'public_ipv4', 'public_ipv6'],
            default => [],
        };
    }

    private function promptForFieldValue(string $field): string
    {
        if ($field === 'environment') {
            return select(
                label: 'Environment',
                options: ['development', 'production'],
                required: true,
            );
        }

        if ($field === 'tld') {
            return trim(text(
                label: 'Development TLD',
                required: true,
                validate: fn (string $value): ?string => $this->isValidTld(trim($value))
                    ? null
                    : 'TLD must be a lowercase DNS label without a leading dot.',
            ));
        }

        return trim(text(
            label: match ($field) {
                'host' => 'Host',
                'public_ipv4' => 'Public IPv4',
                'public_ipv6' => 'Public IPv6',
                default => $field,
            },
            required: true,
        ));
    }

    /**
     * @param  array<string, string|null>  $providedFields
     */
    private function forwardUpdate(string $name, array $providedFields): int
    {
        try {
            $dto = null;
            $operation = function () use ($name, $providedFields, &$dto): string {
                /** @var NodeUpdateResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new UpdateNodeRequest($name, $providedFields))
                    ->dto();

                if ($dto->changed === []) {
                    return 'unchanged';
                }

                return $dto->warnings === [] ? 'updated' : 'updated with drift';
            };

            if (! $this->wantsJson()) {
                $exitCode = $this->runNodeUpdateTree($name, $operation);

                if ($exitCode !== self::SUCCESS || ! $dto instanceof NodeUpdateResponse) {
                    return self::FAILURE;
                }
            } else {
                $operation();
            }
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to update a node.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update a node.',
                meta: [],
            );
        }

        return $this->respondForwardedSuccess($name, $dto);
    }

    private function respondForwardedSuccess(string $fallbackName, NodeUpdateResponse $dto): int
    {
        return $this->respondSuccess(
            $dto->name !== '' ? $dto->name : $fallbackName,
            $dto->changed,
            $dto->warnings,
        );
    }

    /**
     * @param  list<string>  $changed
     * @return list<array<string, string>>
     */
    private function reenactNodeArtifacts(ReenactNodeArtifacts $reenactNodeArtifacts, Node $node, array $changed): array
    {
        if ($changed === []) {
            return [];
        }

        try {
            return $reenactNodeArtifacts->handle($node, $changed);
        } catch (Throwable) {
            return [$this->artifactEnactmentWarning()];
        }
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hasConfiguredGateway(): bool
    {
        return LocalGatewaySettings::query()
            ->whereNotNull('gateway_url')
            ->where('gateway_url', '!=', '')
            ->exists();
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function detectDuplicateFieldFlag(): ?string
    {
        $flags = ['host', 'environment', 'tld', 'public-ipv4', 'public-ipv6'];

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

        if ($this->option('tld') !== null) {
            $fields['tld'] = (string) $this->option('tld');
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

        if (isset($providedFields['tld']) && ! $this->isValidTld($providedFields['tld'])) {
            return [
                'code' => 'validation_failed',
                'message' => "Invalid value for --tld: '{$providedFields['tld']}'. TLD must be a lowercase DNS label without a leading dot.",
                'meta' => [
                    'field' => 'tld',
                    'value' => $providedFields['tld'],
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

        if (isset($providedFields['tld'])) {
            $effectiveEnvironment = $providedFields['environment'] ?? $node->environment;

            if ($role !== 'app' || $effectiveEnvironment !== 'development') {
                return ['field' => 'tld', 'role' => $role];
            }
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

        if (isset($providedFields['tld']) && $providedFields['tld'] !== $node->tld) {
            $changes['tld'] = $providedFields['tld'];
        }

        if (isset($providedFields['public_ipv4']) && $providedFields['public_ipv4'] !== $node->public_ipv4) {
            $changes['public_ipv4'] = $providedFields['public_ipv4'];
        }

        if (isset($providedFields['public_ipv6']) && $providedFields['public_ipv6'] !== $node->public_ipv6) {
            $changes['public_ipv6'] = $providedFields['public_ipv6'];
        }

        return $changes;
    }

    private function isValidTld(string $tld): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld);
    }

    /**
     * @param  list<string>  $changed
     */
    private function respondSuccess(string $name, array $changed, array $warnings = []): int
    {
        $data = [
            'name' => $name,
            'changed' => $changed,
            'action' => 'updated',
        ];

        if ($this->wantsJson()) {
            $success = [
                'data' => $data,
            ];

            if ($warnings !== []) {
                $success['meta'] = [
                    'warnings' => $warnings,
                ];
            }

            $this->line(json_encode([
                'success' => [
                    ...$success,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $footer = match (true) {
            $changed === [] => "Node '{$name}' unchanged",
            $warnings !== [] => "Node '{$name}' updated with drift",
            default => "Node '{$name}' updated",
        };

        if ($changed === []) {
            $this->line("Node '{$name}' unchanged");
            $this->line('  No fields were modified.');
        } else {
            $this->line("Node '{$name}' updated");
            $this->line('  Changed: '.implode(', ', $changed));

            foreach ($warnings as $warning) {
                $this->line('  Drift detected: '.(string) ($warning['message'] ?? $warning['code'] ?? 'Warning'));
            }
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

    private function runNodeUpdateTree(string $name, callable $operation): int
    {
        $steps = [
            [
                'label' => 'Validate node',
                'doneLabel' => 'Validated node',
                'run' => fn (): string => $name,
            ],
            [
                'label' => 'Apply and verify node change',
                'doneLabel' => 'Applied and verified node change',
                'run' => $operation,
            ],
            [
                'label' => 'Apply node artifacts',
                'doneLabel' => 'Applied node artifacts',
                'run' => fn (): string => 'ready',
            ],
        ];

        $width = $this->stepTreeLabelWidth($steps);
        $this->renderStepTree('Updating Node', $steps);
        $updateLine = $this->stepTreeUpdater(count($steps));
        $allOk = true;
        $footer = "Node '{$name}' updated";

        $this->runSequentialWithSpinners(
            array_map(fn (array $step, int $index): callable => function () use ($step, $index, $steps, $width, $updateLine): mixed {
                $updateLine(
                    $index,
                    $this->stepTreeSpinner(self::$spinnerFrames[0], $steps[$index]['label'], $width),
                );

                return ($step['run'])();
            }, $steps, array_keys($steps)),
            fn (int $index, string $frame): mixed => $updateLine(
                $index,
                $this->stepTreeSpinner($frame, $steps[$index]['label'], $width),
            ),
            function (int $index, mixed $message) use (&$allOk, &$footer, $name, $steps, $width, $updateLine): void {
                $message = (string) $message;
                $label = $steps[$index]['doneLabel'];

                if ($message === 'unchanged') {
                    $footer = "Node '{$name}' unchanged";
                }

                if ($message === 'updated with drift') {
                    $footer = "Node '{$name}' updated with drift";
                }

                if (str_starts_with($message, 'fail:')) {
                    $allOk = false;
                    $updateLine($index, $this->stepFailed($label, $width, substr($message, 5)));

                    return;
                }

                $updateLine($index, $this->stepSuccess($label, $width, $message));
            },
            function (int $index, Throwable $e) use (&$allOk, $steps, $width, $updateLine): void {
                $allOk = false;
                $updateLine($index, $this->stepFailed($steps[$index]['doneLabel'], $width, $e->getMessage()));
            },
        );

        $this->finishStepTree($allOk ? $footer : "Failed to update node '{$name}'", $allOk);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{code: string, message: string, family: string, next_command: string}
     */
    private function artifactEnactmentWarning(): array
    {
        return [
            'code' => 'node.artifact_enactment_failed',
            'message' => 'Node artifact re-enactment failed after intent update.',
            'family' => 'node',
            'next_command' => 'doctor --fix --family=node --restore',
        ];
    }
}
