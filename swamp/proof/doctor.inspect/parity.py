"""Rule 22 parity: a collection run reads the Gateway's own log in the Gateway; the run carries no log input."""
import json
import os
import subprocess

operation = json.loads(os.environ['PROOF_OPERATION'])
assert operation['status'] == 'succeeded', operation['status']
assert 'logs' not in operation['input'], 'the run still receives a log intent'
code = r'''require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $log=storage_path("logs/laravel.log"); clearstatcache(); $state=App\Models\ProblemCollectorState::query()->sole(); if ($state->log_path!==$log || $state->log_inode!==fileinode($log) || $state->log_offset<=0) exit(1);'''
subprocess.run(['runuser', '-u', 'orbit', '--', 'php', '-r', code], cwd='apps/gateway', env=os.environ, check=True)
print('parity: the Gateway read its own log and stored the cursor; the run had no log step')
