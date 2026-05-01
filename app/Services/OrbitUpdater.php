<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Node;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class OrbitUpdater
{
    public function updateLocal(): ProcessResult
    {
        return Process::path(base_path())
            ->timeout(600)
            ->run($this->updateCommand());
    }

    public function updateRemote(Node $node): ProcessResult
    {
        $target = "{$node->ssh_user}@{$node->host}";
        $remoteCommand = "cd {$node->orbit_path} && {$this->updateCommand()}";

        return Process::timeout(600)
            ->run("ssh {$target} ".escapeshellarg($remoteCommand));
    }

    public function updateCommand(): string
    {
        return 'git pull --ff-only && composer install --no-interaction && php artisan migrate --force';
    }
}
