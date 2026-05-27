<?php

declare(strict_types=1);

it('waits for operator host-key scan reachability before checkout pinning runs', function (): void {
    $providerSource = file_get_contents(repo_path('apps/gateway/app/E2E/Support/IncusTopologyProvider.php'));
    $checkoutSource = file_get_contents(repo_path('apps/gateway/app/E2E/Support/E2ECurrentCheckout.php'));

    expect($providerSource)->toContain('waitForOperatorHostKeyScan')
        ->and($providerSource)->toContain('ssh-keyscan -T 5 -t ed25519,ecdsa,rsa')
        ->and($providerSource)->toContain('$this->waitForOperatorHostKeyScan($operator, $config, $wireGuardIp);')
        ->and($checkoutSource)->toContain("self::artisanCommand('orbit:internal:pin-node-host-keys --json'");
});
