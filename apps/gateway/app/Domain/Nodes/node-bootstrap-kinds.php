<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeBootstrapChildAdmission;
use App\Domain\Nodes\NodeBootstrapOperationCompletion;
use App\Infrastructure\Nodes\BootstrapSshOperationTransport;

$fields = ['bootstrap_host', 'bootstrap_port', 'bootstrap_fingerprint', 'bootstrap_user', 'bootstrap_identity', 'command_input'];
$bootstrapKinds = [];
foreach (['node.bootstrap_probe' => ['node.probe-bootstrap', 'bootstrap-probe', 'probed'],
    'node.bootstrap_install' => ['agent.install', 'agent-install', 'installed']] as $kind => [$command, $step, $proof]) {
    $parameters = $command === 'agent.install' ? ['agent_pin', ...$fields] : $fields;
    $bootstrapKinds[$kind] = [
        'admission' => NodeBootstrapChildAdmission::class,
        'executor' => 'swamp', 'completion' => NodeBootstrapOperationCompletion::class,
        'runs' => [[
            'transport' => 'bootstrap-ssh', 'name' => $command, 'resumable' => $command === 'node.probe-bootstrap',
            'bind' => array_combine($parameters, array_map(static fn (string $field): string => 'input.'.$field, $parameters)),
            'secrets' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity'],
            'input_schema' => BootstrapSshOperationTransport::inputSchema($command),
        ]],
        'completion_contract' => ['step' => $step, 'transport' => 'bootstrap-ssh', 'name' => $command, 'result' => [$proof => true]],
        'result_fields' => ['probed', 'installed', 'changed', 'architecture', 'host_type', 'public_key', 'host_key_fingerprint', 'error_code'],
    ];
}

return $bootstrapKinds;
