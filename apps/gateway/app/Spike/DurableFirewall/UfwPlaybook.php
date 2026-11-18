<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Workflow\V2\Activity;

/**
 * SPIKE: one durable activity = one Ansible run against one Node. Runs the given ufw argv lists in order, then
 * returns `ufw status numbered`. Safe to repeat: a re-run after a lost worker re-reads, and ufw refuses duplicates.
 */
final class UfwPlaybook extends Activity
{
    public ?string $connection = 'gateway';

    public ?string $queue = 'dw-activity';

    public int $tries = 3;

    private const ANSIBLE = '/opt/orbit/ansible/venv/bin/ansible-playbook';

    /** @param array{identity_file: string, target: array{host: string, user: string, port: int, host_keys: list<string>}} $ssh */
    public function handle(array $ssh, array $commands): string
    {
        $dir = storage_path('spike-dw/'.$this->activityId().'-'.($this->attemptId() ?? 'x'));
        File::ensureDirectoryExists($dir, 0700);
        try {
            $target = $ssh['target'];
            File::put("$dir/known_hosts", implode("\n", array_map(fn (string $key): string => "{$target['host']} $key", $target['host_keys']))."\n");
            File::put("$dir/inventory.json", json_encode(['all' => ['hosts' => ['target' => [
                'ansible_host' => $target['host'],
                'ansible_user' => $target['user'],
                'ansible_port' => $target['port'],
                'ansible_ssh_private_key_file' => $ssh['identity_file'],
                'ansible_ssh_common_args' => "-o UserKnownHostsFile=$dir/known_hosts -o StrictHostKeyChecking=yes -o BatchMode=yes",
                'ansible_python_interpreter' => 'auto_silent',
            ]]]], JSON_THROW_ON_ERROR));
            File::put("$dir/vars.json", json_encode(['ufw_commands' => $commands, 'ufw_out' => "$dir/ufw.out"], JSON_THROW_ON_ERROR));

            $result = Process::timeout(120)->env([
                'ANSIBLE_PIPELINING' => '1',
                'ANSIBLE_HOST_KEY_CHECKING' => 'True',
                'ANSIBLE_LOCAL_TEMP' => "$dir/tmp",
                'ANSIBLE_NOCOLOR' => '1',
            ])->run([self::ANSIBLE, '-i', "$dir/inventory.json", '-e', "@$dir/vars.json", __DIR__.'/ufw.yml']);

            if ($result->successful() && File::exists("$dir/ufw.out")) {
                return File::get("$dir/ufw.out");
            }

            throw new RuntimeException('ufw playbook failed: '.substr(trim($result->output()."\n".$result->errorOutput()), -800));
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
