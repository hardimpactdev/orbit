<?php

declare(strict_types=1);

use App\Domain\Fleet\CliConvergeExecution;
use App\Domain\Fleet\CliConvergeResult;
use App\Domain\Fleet\FleetCliChildAdmission;
use App\Domain\Fleet\FleetKitCapabilities;
use App\Domain\Fleet\FleetOperationExecution;
use App\Domain\Fleet\PeerDnsOperationCompletion;
use App\Domain\Fleet\RecipeOperationCompletion;
use App\Domain\Fleet\RecipePrOperationCompletion;
use App\Domain\Fleet\RolloutOperationCompletion;
use App\Support\ValidatedData;

return (static function (): array {
    $kinds = [
        'fleet.peer_dns' => [
            'executor' => 'swamp',
            'completion' => PeerDnsOperationCompletion::class,
            'runs' => [
                ['transport' => 'agent', 'name' => 'wireguard.inspect-peer-dns', 'input_fields' => ['server'], 'input_values' => []],
                ['transport' => 'agent', 'name' => 'wireguard.repair-peer-dns', 'input_fields' => ['dns_server', 'public_key', 'domains'], 'input_values' => []],
            ],
            'completion_contract' => [
                'step' => 'peer-dns-verify', 'transport' => 'agent', 'name' => 'wireguard.inspect-peer-dns', 'result' => ['uplink' => true],
            ],
            'result_fields' => ['state', 'hooks', 'dns', 'domains', 'default_route', 'uplink', 'error_code'],
        ],
    ];

    $recipeFields = ['user', 'snapshot', 'git_packages', 'artifact_mirror', 'architecture', 'tools', 'vp_lock'];
    $kinds['fleet.recipe'] = [
        'executor' => 'swamp', 'completion' => RecipeOperationCompletion::class, 'execution' => FleetOperationExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'fleet.recipe-begin', 'resumable' => true, 'input_fields' => [], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'recipe.incus-preflight', 'resumable' => true, 'input_fields' => $recipeFields, 'input_values' => []],
            ['transport' => 'agent', 'name' => 'recipe.apply', 'resumable' => true, 'input_fields' => $recipeFields, 'input_values' => []],
            ['transport' => 'agent', 'name' => 'php.install-build', 'resumable' => true, 'input_fields' => ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release'], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'recipe.verify-delivery', 'resumable' => true, 'input_fields' => ['user', 'identity', 'agent_sha256', 'cli_version'], 'input_values' => []],
            ['transport' => 'gateway', 'name' => 'fleet.verify', 'resumable' => true, 'input_fields' => [], 'input_values' => ['mode' => 'recipe']],
        ],
        'children' => [['kind' => 'node.converge', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['step' => 'recipe-delivery', 'transport' => 'agent', 'name' => 'recipe.verify-delivery', 'result' => []],
        'result_fields' => ['started', 'ready', 'healthy', 'identity', 'agent_sha256', 'cli_version', 'outcome', 'passed', 'agent_version', 'presence', 'error_code',
            ['name' => 'changed_packages', 'type' => 'list', 'items' => 'string', 'max' => 512],
            ['name' => 'changed_tools', 'type' => 'list', 'items' => 'string', 'max' => 512],
            ['name' => 'restarted_services', 'type' => 'list', 'items' => 'string', 'max' => 512]],
    ];
    $recipe = ValidatedData::object(json_decode(ValidatedData::string(file_get_contents(resource_path('machine-software/recipe.json'))), true, flags: JSON_THROW_ON_ERROR));
    foreach (array_keys(ValidatedData::object($recipe['tools'])) as $name) {
        foreach (['installed', 'version'] as $field) {
            $kinds['fleet.recipe']['result_fields'][] = 'newer_than_recipe.'.$name.'.'.$field;
        }
    }
    $kinds['fleet.recipe_pr'] = [
        'executor' => 'swamp', 'completion' => RecipePrOperationCompletion::class,
        'runs' => [['transport' => 'gateway', 'name' => 'github.recipe-pr', 'resumable' => true,
            'input_fields' => ['action', 'variant', 'date', 'pull_number', 'head_sha', 'token'], 'input_values' => [],
            'secrets' => ['token' => 'github.app']]],
        'completion_contract' => ['step' => 'recipe-pr', 'transport' => 'gateway', 'name' => 'github.recipe-pr', 'result' => ['completed' => true]],
        'result_fields' => ['completed', 'outcome', 'url', 'error_code'],
    ];
    $kinds['fleet.rollout'] = [
        'executor' => 'swamp', 'completion' => RolloutOperationCompletion::class, 'execution' => FleetOperationExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'fleet.probe', 'resumable' => true, 'input_fields' => [], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'cli.install', 'resumable' => true, 'input_fields' => ['user', 'release', 'profile'], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'cli.self-update', 'resumable' => true, 'input_fields' => ['user'], 'input_values' => ['downgrade_to' => null]],
            ['transport' => 'agent', 'name' => 'cli.self-update', 'resumable' => true, 'input_fields' => ['user', 'downgrade_to'], 'input_values' => []],
            ['transport' => 'gateway', 'name' => 'fleet.verify', 'resumable' => true, 'input_fields' => [], 'input_values' => ['mode' => 'rollout']],
        ],
        'children' => [['kind' => 'node.converge', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['step' => 'verify', 'transport' => 'gateway', 'name' => 'fleet.verify', 'result' => ['passed' => true], 'children_succeeded' => ['node.converge']],
        'result_fields' => ['reachable', 'installed', 'configured', 'outcome', 'cli_version', 'exit_code', 'passed', 'agent_version', 'presence', 'changed', 'caddy_skipped', 'error_code',
            'report_present', 'report.status', 'report.code'],
    ];

    if (! FleetKitCapabilities::visitsSupported()) {
        unset($kinds['fleet.recipe'], $kinds['fleet.rollout']);
    }

    if (FleetKitCapabilities::cliSupported()) {
        $kinds['fleet.cli_converge'] = [
            'executor' => 'swamp', 'admission' => FleetCliChildAdmission::class,
            'completion' => CliConvergeResult::class, 'execution' => CliConvergeExecution::class,
            'runs' => [
                ['transport' => 'agent', 'name' => 'cli.install', 'resumable' => true, 'input_fields' => ['user', 'release', 'profile'], 'input_values' => []],
                ['transport' => 'gateway', 'name' => 'fleet.cli-converge-result', 'resumable' => true, 'input_fields' => [], 'input_values' => []],
            ],
            'completion_contract' => ['step' => 'cli-converge', 'transport' => 'gateway', 'name' => 'fleet.cli-converge-result', 'result' => ['completed' => true]],
            'result_fields' => ['completed', 'outcome', 'reason', 'version', 'configured', 'installed', 'error_code'],
        ];
    }

    return $kinds;
})();
