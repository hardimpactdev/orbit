<?php

declare(strict_types=1);

use App\Domain\WireGuard\Ipv4Subnet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $scope = DB::table('settings')->where('scope_type', 'gateway')->where('scope_id', 0);
        $configuredSubnet = (clone $scope)->where('key', 'vpn.subnet')->value('value');
        $subnet = Ipv4Subnet::from(is_string($configuredSubnet) ? $configuredSubnet : '10.44.0.0/24');
        $server = (clone $scope)->where('key', 'vpn.dns_server')->value('value');
        if (is_string($server) && ! $subnet->containsUsableAddress($server)) {
            (clone $scope)->where('key', 'vpn.dns_server')->update(['value' => null]);
        }
    }
};
