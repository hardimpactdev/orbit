<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-operator', 'e2e-feature-operator');

it('reports unsupported_platform on Linux when given valid arguments', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Operator)
        ->withCurrentCheckout(['operator']);

    try {
        $config = E2EConfig::fromEnvironment();
        $checkout = escapeshellarg($topology->checkout('operator'));
        $operator = $topology->instance('operator');
        $key = $topology->lease()->sshKeyPair();

        $result = $operator->ssh($config->operatorUser, $key, "cd {$checkout} && orbit dns:resolve-tld test 10.6.0.7 --json");

        expect($result->successful())->toBeFalse(
            'Expected dns:resolve-tld to fail on Linux, but it succeeded. Output: '.$result->output().$result->errorOutput()
        );

        $output = trim($result->output());
        $json = json_decode($output, true);

        expect($json)->toHaveKey('error');
        expect($json['error'])->toHaveKey('code');
        expect($json['error']['code'])->toContain('unsupported_platform');

        $result = $operator->ssh($config->operatorUser, $key, "cd {$checkout} && orbit dns:resolve-tld --json");

        expect($result->successful())->toBeFalse(
            'Expected dns:resolve-tld without args to fail validation, but it succeeded. Output: '.$result->output().$result->errorOutput()
        );

        $output = trim($result->output());
        $json = json_decode($output, true);

        expect($json)->toHaveKey('error');
        expect($json['error'])->toHaveKey('code');
        expect($json['error']['code'])->toBe('validation_failed');
    } finally {
        $topology->cleanup();
    }
});
