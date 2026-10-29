<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

final class TaskVmUpCloudImageContracts
{
    /** @return array<string,array<string,mixed>> */
    public static function kinds(): array
    {
        $kinds = [];
        foreach (['prepare', 'launch-builder', 'launch-smoke', 'stop', 'hard-stop-builder', 'hard-stop-smoke', 'templatize', 'delete-builder', 'delete-smoke', 'promote', 'prune-template', 'discard-template'] as $verb) {
            $action = str_starts_with($verb, 'launch-') ? 'launch' : $verb;
            $fixed = str_starts_with($verb, 'hard-stop-') ? ['action' => 'hard-stop', 'slot' => substr($verb, strlen('hard-stop-'))] : ['action' => $action];
            $fields = ['build_id'];
            $secrets = [];
            if ($verb !== 'prepare') {
                $fields[] = 'token';
                $secrets['token'] = 'upcloud.api';
            }
            if (str_starts_with($verb, 'launch-')) {
                $fixed['slot'] = $verb === 'launch-smoke' ? 'smoke' : 'builder';
                $fields[] = 'bootstrap';
                $secrets['bootstrap'] = 'task_vm.image_bootstrap';
            }
            if ($verb === 'prune-template') {
                $fields[] = 'template_uuid';
            }
            $properties = ['build_id' => ['type' => 'integer', 'minimum' => 1]];
            foreach ($fields as $field) {
                if ($field !== 'build_id') {
                    $properties[$field] = $field === 'bootstrap' ? ['type' => 'object'] : ['type' => 'string'];
                }
            }
            foreach ($fixed as $field => $value) {
                $properties[$field] = ['const' => $value];
            }
            $kinds['task_vm.upcloud_image_'.str_replace('-', '_', $verb)] = self::definition($verb, $fields, $fixed, $properties, $secrets);
        }
        foreach ([...TaskVmCommandContracts::GuestCommands, ...TaskVmCommandContracts::SpecialGuestCommands] as $command) {
            if (in_array($command, ['image.restart-phase', 'image.inspect-phase', 'guest.fingerprint'], true)) {
                continue;
            }
            $step = 'upcloud-guest-'.str_replace('.', '-', $command);
            $properties = ['build_id' => ['type' => 'integer', 'minimum' => 1], 'action' => ['const' => 'guest'], 'slot' => ['enum' => ['builder', 'smoke']],
                'command' => ['const' => $command], 'parameters_json' => ['type' => 'string', 'maxLength' => 4_194_304]];
            $definition = self::definition($step, ['build_id', 'slot', 'parameters_json'], ['action' => 'guest', 'command' => $command], $properties, [], $command === 'image.start-phase' ? [] : ['ready' => true]);
            $kinds['task_vm.upcloud_guest_'.str_replace(['.', '-'], '_', $command)] = $definition;
        }
        $children = array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.node_id'], array_keys($kinds));
        $kinds['task_vm.upcloud_image_build'] = ['executor' => 'swamp', 'completion' => TaskVmUpCloudImageCompletion::class, 'runs' => [], 'children' => $children,
            'completion_contract' => ['children_succeeded' => ['task_vm.upcloud_image_promote']],
            'result_fields' => ['ready', 'template_uuid', 'error_code']];

        return $kinds;
    }

    /** @param list<string> $fields
     * @param  array<string,mixed>  $fixed
     * @param  array<string,mixed>  $properties
     * @param  array<string,string>  $secrets
     * @param  array<string,mixed>  $proof
     * @return array<string,mixed>
     */
    private static function definition(string $step, array $fields, array $fixed, array $properties, array $secrets, array $proof = ['ready' => true]): array
    {
        return ['executor' => 'swamp', 'completion' => TaskVmEffectCompletion::class, 'runs' => [[
            'transport' => 'task_vm_upcloud-image', 'name' => 'task_vm.upcloud-image', 'input_fields' => $fields, 'input_values' => $fixed, 'secrets' => $secrets,
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties]]],
            'completion_contract' => ['step' => $step, 'transport' => 'task_vm_upcloud-image', 'name' => 'task_vm.upcloud-image', 'result' => $proof],
            'result_fields' => ['ready', 'template_uuid', 'state', 'boot_id', 'unit', 'invocation_id', 'exit_code', 'reason', 'error_code']];
    }
}
