<?php

declare(strict_types=1);

use App\Infrastructure\Certificates\OrbitLeafCertificate;

it('renders one TLS server extension section for every Orbit leaf', function (string $section, string $names): void {
    expect(OrbitLeafCertificate::extensions($section, $names))->toBe(
        "[{$section}]\n"
        ."basicConstraints = critical,CA:FALSE\n"
        ."keyUsage = critical,digitalSignature,keyEncipherment\n"
        ."extendedKeyUsage = serverAuth\n"
        ."subjectAltName = {$names}",
    );
})->with([
    'Gateway leaf' => ['gateway', 'DNS:gateway.orbit,IP:10.44.0.1'],
    'development leaf' => ['leaf', 'DNS:site.app-dev.orbit'],
]);

it('signs leaves for 397 days, inside the validity that the agent accepts for a current leaf', function (): void {
    expect(OrbitLeafCertificate::ValidityDays)->toBe(397);
});
