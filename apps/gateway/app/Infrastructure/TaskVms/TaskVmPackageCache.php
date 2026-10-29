<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentGitHubToken;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Models\Node;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use App\Models\TaskVm;
use Closure;
use Illuminate\Support\Facades\Config;

final readonly class TaskVmPackageCache implements TaskVmPackageCacheMount
{
    public const string HostPath = '/var/lib/orbit/package-cache';

    public const string GuestPath = '/opt/orbit/package-cache';

    public const string Device = 'package-cache';

    public function __construct(private AgentCommandRunner $agents, private GuestAgentCommandRunner $guests) {}

    /** @param list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}> $artifacts
     * @return array{hashes:array<string,string>,failures:array<string,string>}
     */
    public function fill(TaskVmHost $host, Node $node, array $artifacts, #[\SensitiveParameter] ?string $githubToken = null): array
    {
        $maxBytes = Config::integer('package_cache.max_bytes');
        if ($maxBytes < 1) {
            throw new TaskVmException('task_vm.package_cache_invalid_limit', 'The package cache byte budget must be positive.');
        }
        $response = $this->request(fn () => $this->agents->run($node, 'package-cache.host', ['operation' => 'fill', 'artifacts' => $artifacts, 'max_bytes' => $maxBytes, 'github_token' => $githubToken === null ? null : new AgentGitHubToken($githubToken)]));

        return self::verifiedResult($artifacts, $response);
    }

    /**
     * @param  list<array{manager: string, package: string, version: string, url: string, integrity: string|null, key: string}>  $artifacts
     * @param  array<string,mixed>  $run
     * @return array{hashes: array<string, string>, failures: array<string, string>}
     */
    public function fillProven(TaskVmHost $host, Node $node, array $artifacts, #[\SensitiveParameter] ?string $githubToken, int $maxBytes, array $run): array
    {
        if ($maxBytes < 1) {
            throw new TaskVmException('task_vm.package_cache_invalid_limit', 'The package cache byte budget must be positive.');
        }
        $response = $this->request(fn () => $this->agents->run($node, 'package-cache.host', AgentEffectReceipts::input($run, ['operation' => 'fill', 'artifacts' => $artifacts, 'max_bytes' => $maxBytes, 'github_token' => $githubToken === null ? null : new AgentGitHubToken($githubToken)])));

        return self::verifiedResult($artifacts, $response);
    }

    /** @param list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}> $artifacts
     * @param  array<string,mixed>  $response
     * @return array{hashes:array<string,string>,failures:array<string,string>}
     */
    public static function verifiedResult(array $artifacts, array $response): array
    {
        $hashes = $response['hashes'] ?? null;
        $failures = $response['failures'] ?? null;
        if (! is_array($hashes) || ! is_array($failures) || array_diff(array_keys($hashes + $failures), array_column($artifacts, 'key')) !== []) {
            throw new TaskVmException('task_vm.package_cache_invalid_result', 'The trusted fill returned an invalid artifact result.');
        }
        foreach ($artifacts as $artifact) {
            $key = $artifact['key'];
            $hash = $hashes[$key] ?? null;
            $failure = $failures[$key] ?? null;
            if (array_key_exists($key, $hashes) === array_key_exists($key, $failures)
                || (array_key_exists($key, $hashes) && (! is_string($hash) || preg_match('/\Asha256-[a-f0-9]{64}\z/D', $hash) !== 1))
                || (array_key_exists($key, $failures) && (! is_string($failure) || preg_match('/\A[\x20-\x7e]{1,160}\z/D', $failure) !== 1))) {
                throw new TaskVmException('task_vm.package_cache_invalid_result', 'The trusted fill returned no verified archive hash or artifact failure.');
            }
        }

        return ['hashes' => array_filter($hashes, is_string(...)), 'failures' => array_filter($failures, is_string(...))];
    }

    /** Attach at workspace creation, and keep all writes on the workspace disk. */
    public function prepare(TaskVm $vm, Project $project): void
    {
        if ($vm->provider->value !== 'incus') {
            return;
        }
        $host = app(TaskVmSettings::class)->host((int) $vm->host_node_id);
        $node = $vm->incusHostNode();
        $this->request(fn () => $this->agents->run($node, 'package-cache.host', ['operation' => 'initialize']));
        $this->confirmed($this->request(fn () => $this->agents->run($node, 'incus.package-cache', ['project' => $host->project, 'name' => $vm->name])));
        $artifacts = PackageCacheSnapshot::query()->find($project->id)->artifacts ?? [];
        $this->confirmed($this->request(fn () => $this->guests->runGuest(AgentGuest::incus($node, $host->project, $vm->name), 'package-cache.seed', ['artifacts' => $artifacts])));
    }

    /** @param array<string, mixed> $result */
    private function confirmed(array $result): void
    {
        if (($result['ready'] ?? null) !== true) {
            throw new TaskVmException('task_vm.package_cache_invalid_result', 'The cache command did not confirm completion.');
        }
    }

    /**
     * @param  Closure(): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function request(Closure $work): array
    {
        try {
            return $work();
        } catch (AgentCommandRefused $error) {
            if ($error->errorCode === 'task_vm.package_cache_mount_invalid') {
                throw new TaskVmException($error->errorCode, $error->getMessage(), 502, $error);
            }
            if ($error->errorCode !== 'task_vm.package_cache_failed') {
                throw $error;
            }
            $reason = is_string($error->details['reason'] ?? null) ? $error->details['reason'] : $error->getMessage();
            $operation = is_string($error->details['operation'] ?? null) ? $error->details['operation'] : $error->command;
            $path = is_string($error->details['path'] ?? null) ? $error->details['path'] : self::GuestPath;
            $detail = new CommandActivityInputSanitizer()->redactText(mb_scrub($reason, 'UTF-8'));
            $detail = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $detail) ?? '';
            $tail = mb_substr(trim($detail), -1200);
            throw new TaskVmException($error->errorCode, "The trusted package cache operation [{$operation}] failed for [{$path}].".($tail === '' ? '' : "\n{$tail}"), 502, $error);
        }
    }
}
