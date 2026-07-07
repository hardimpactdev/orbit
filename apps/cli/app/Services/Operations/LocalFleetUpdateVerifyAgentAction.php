<?php

declare(strict_types=1);

namespace App\Services\Operations;

final readonly class LocalFleetUpdateVerifyAgentAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(array $payload): array
    {
        $binPath = LocalFleetUpdateVerifyBinPath::fromPayload($payload['bin_path'] ?? null, 'orbit-agent');
        $expectedSha256 = $this->sha256($payload['sha256'] ?? null);

        if (! is_file($binPath) || ! is_readable($binPath)) {
            throw new LocalFleetUpdateVerifyFailure(
                errorCode: 'fleet_update.agent_verification_failed',
                message: 'Orbit Agent verification failed.',
                meta: [
                    'bin_path' => $binPath,
                ],
            );
        }

        $actualSha256 = hash_file('sha256', $binPath);

        if (is_string($actualSha256) && hash_equals($expectedSha256, strtolower($actualSha256))) {
            return [
                'check' => 'agent',
                'verified' => true,
                'bin_path' => $binPath,
                'sha256' => $expectedSha256,
            ];
        }

        throw new LocalFleetUpdateVerifyFailure(
            errorCode: 'fleet_update.agent_verification_failed',
            message: 'Orbit Agent verification failed.',
            meta: [
                'bin_path' => $binPath,
                'expected_sha256' => $expectedSha256,
                'actual_sha256' => is_string($actualSha256) ? strtolower($actualSha256) : null,
            ],
        );
    }

    private function sha256(mixed $sha256): string
    {
        if (is_string($sha256) && preg_match('/\A[a-f0-9]{64}\z/i', $sha256) === 1) {
            return strtolower($sha256);
        }

        throw new LocalFleetUpdateVerifyFailure(
            errorCode: 'validation_failed',
            message: 'Fleet update verification sha256 is invalid.',
            meta: ['field' => 'sha256'],
        );
    }
}
