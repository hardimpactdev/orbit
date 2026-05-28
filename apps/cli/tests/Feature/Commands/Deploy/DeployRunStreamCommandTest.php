<?php

declare(strict_types=1);

describe('DeployRunStream command', function (): void {
    it('fails when the DeployRunStream contains a malformed frame', function (): void {
        fakeGatewayProgressStream("event: step\ndata: not-json\n\n");

        [$exitCode, $output] = runCommand($this, 'deploy:run', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable')
            ->and($decoded['error']['message'])->toContain('malformed');
    });
});
