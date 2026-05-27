<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('orbit:internal:node-register
    {name : Registry name for the node}
    {--role=control : Node role: gateway, control, or app}
    {--host= : SSH host or alias}
    {--user= : SSH user}
    {--orbit-path= : Path to the Orbit checkout on the node}
    {--status=active : Node status}
    {--local : Deprecated no-op; local node rows are no longer stored}')]
#[Description('Register or update a node in the gateway registry')]
class NodeRegisterCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $role = (string) $this->option('role');
        $status = (string) $this->option('status');

        if (! in_array($role, ['gateway', 'control', 'app'], true)) {
            $this->error('Role must be one of: gateway, control, app.');

            return self::FAILURE;
        }

        if (! in_array($status, ['active', 'inactive'], true)) {
            $this->error('Status must be one of: active, inactive.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        DB::transaction(function () use ($name, $role, $status): void {
            Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'role' => $role,
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
}
