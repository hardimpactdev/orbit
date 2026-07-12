<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    public function handle(): int
    {
        $status = NodeStatus::tryFrom((string) $this->option('status'));

        if (! $status instanceof NodeStatus || ! in_array($status, [NodeStatus::Active, NodeStatus::Inactive], true)) {
            $this->error('Status must be one of: active, inactive.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $tld = trim((string) $this->option('tld'));

        if ($status === NodeStatus::Active && ! $this->validTld($tld)) {
            $this->error('Active nodes require a unique lowercase DNS-label TLD.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $status, $tld): void {
            Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'tld' => $tld !== '' ? $tld : null,
                    'host' => (string) ($this->option('host') ?: $name),
                    'user' => (string) ($this->option('user') ?: get_current_user()),
                    'orbit_path' => (string) ($this->option('orbit-path') ?: repo_path()),
                    'status' => $status,
                ],
            );
        });

        $this->info("Registered node {$name}.");

        return self::SUCCESS;
    }

    private function validTld(string $tld): bool
    {
        return strlen($tld) <= 63 && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld) === 1;
    }
}
