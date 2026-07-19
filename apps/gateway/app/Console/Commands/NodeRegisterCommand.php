<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Orbit\Core\Nodes\NodeTld;

#[Signature('orbit:internal:node-register
    {name : Registry name for the node}
    {--tld= : Explicit unique TLD assigned to the node}
    {--host= : SSH host or alias}
    {--user= : SSH user}
    {--orbit-path= : Path to the Orbit checkout on the node}
    {--status=active : Node status}')]
#[Description('Register or update a node in the gateway registry')]
class NodeRegisterCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(DnsmasqReconciler $dnsmasqReconciler): int
    {
        $status = NodeStatus::tryFrom((string) $this->option('status'));

        if (! $status instanceof NodeStatus || ! in_array($status, [NodeStatus::Active, NodeStatus::Inactive], true)) {
            $this->error('Status must be one of: active, inactive.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $tldOption = $this->option('tld');
        $hostOption = $this->option('host');
        $userOption = $this->option('user');
        $orbitPathOption = $this->option('orbit-path');
        $tld = is_string($tldOption) ? $tldOption : '';
        $host = is_string($hostOption) && $hostOption !== '' ? $hostOption : $name;
        $user = is_string($userOption) && $userOption !== '' ? $userOption : get_current_user();
        $orbitPath = is_string($orbitPathOption) && $orbitPathOption !== '' ? $orbitPathOption : repo_path();

        if ($status === NodeStatus::Active && ! NodeTld::isValid($tld)) {
            $this->error('Active nodes require a unique non-reserved lowercase DNS-label TLD.');

            return self::FAILURE;
        }

        DB::transaction(static function () use ($host, $name, $orbitPath, $status, $tld, $user): void {
            Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'tld' => $tld !== '' ? $tld : null,
                    'host' => $host,
                    'user' => $user,
                    'orbit_path' => $orbitPath,
                    'status' => $status,
                ],
            );
        });

        $dnsmasqReconciler->reconcileRecords();

        $this->info("Registered node {$name}.");

        return self::SUCCESS;
    }
}
