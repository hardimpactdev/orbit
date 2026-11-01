<?php

declare(strict_types=1);

require __DIR__.'/configured_policy_proof.php';
$grant = ['id' => 'exact', 'resource' => ['kind' => 'vault', 'pattern' => 'inputs'],
    'actions' => ['write', 'read'], 'condition' => "key.matches('^owned-[0-9]+$')"];
$workflow = ['name' => 'operation-example', 'id' => '11111111-2222-3333-4444-555555555555'];
$policy = ['grants' => [$grant], 'registered_workflows' => [$workflow]];
$run = static function (string $fault = '') use ($grant, $workflow, $policy): array {
    $rpc = static function (string $type, array $payload) use ($grant, $workflow, $fault): array {
        if ($type === 'workflow.get') {
            return $fault === 'uuid' ? [...$workflow, 'id' => 'wrong'] : $workflow;
        }
        $principal = $fault === 'principal' ? 'user:other' : 'user:orbit-gateway';
        if ($payload !== [] && in_array($payload['action'] ?? null, ['run', 'read'], true) && ($payload['resource'] ?? null) === 'workflow:'.$workflow['name']) {
            return ['principal' => $principal, 'decisions' => $fault === 'deny' ? [['effect' => 'allow'], ['effect' => 'deny']] : [['effect' => 'allow']]];
        }
        if ($payload !== []) {
            return ['principal' => $principal, 'decisions' => $fault === 'broad' ? [['effect' => 'allow']] : []];
        }
        $decisions = [];
        foreach ($grant['actions'] as $action) {
            $decisions[] = ['grantId' => $grant['id'], 'resource' => 'vault:inputs', 'action' => $action,
                'effect' => 'allow', 'condition' => $fault === 'condition' ? null : $grant['condition']];
        }
        if ($fault === 'missing') {
            array_pop($decisions);
        }
        if ($fault === 'duplicate') {
            $decisions[] = $decisions[0];
        }
        return ['principal' => $principal, 'decisions' => $decisions];
    };
    return proveConfiguredManifestPolicy($rpc, $policy);
};
if ($run()['verified'] !== true) {
    throw new RuntimeException('valid_proof_failed');
}
foreach (['principal', 'uuid', 'condition', 'missing', 'duplicate', 'broad', 'deny'] as $fault) {
    $failed = false;
    try {
        $run($fault);
    } catch (RuntimeException) {
        $failed = true;
    }
    if (!$failed) {
        throw new RuntimeException('invalid_proof_accepted:'.$fault);
    }
}
echo "configured-token proof: 8 cases passed\n";
