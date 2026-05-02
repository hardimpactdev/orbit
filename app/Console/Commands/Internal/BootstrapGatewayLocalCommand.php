<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('orbit:internal:bootstrap-gateway-local
    {name : Gateway node name}
    {wireguard-address : WireGuard address for the gateway}')]
#[Description('Bootstrap gateway-local identity and root CA on the gateway host')]
class BootstrapGatewayLocalCommand extends Command
{
    public function handle(OrbitCaService $caService): int
    {
        $name = $this->stringArgument('name');
        $wireguardAddress = $this->stringArgument('wireguard-address');

        if ($name === null || $wireguardAddress === null) {
            throw new RuntimeException('Name and wireguard-address are required.');
        }

        DB::transaction(function () use ($name, $wireguardAddress): void {
            Node::query()->where('is_local', true)->update(['is_local' => false]);

            Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'role' => 'gateway',
                    'environment' => null,
                    'tld' => null,
                    'platform' => 'unknown',
                    'host' => $wireguardAddress,
                    'wireguard_address' => $wireguardAddress,
                    'gateway_endpoint' => null,
                    'ssh_user' => 'orbit',
                    'user' => 'orbit',
                    'orbit_path' => '/home/orbit/orbit',
                    'status' => 'active',
                    'is_local' => true,
                ],
            );
        });

        $caService->ensureRootCa();

        $this->line($caService->rootCert());

        return self::SUCCESS;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
